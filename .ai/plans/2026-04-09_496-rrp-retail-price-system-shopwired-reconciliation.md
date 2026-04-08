# Fix: Route RRP via dedicated retail price system with ShopWired reconciliation

## Context

The ShopWired batch `POST products/prices` endpoint doesn't support `comparePrice` (RRP), causing all price updates to fail with `updated: false` when RRP is included. Rather than patching the routing, this redesigns the RRP write path properly:

1. Store RRP per-SKU in our own database (`catalog.product_extra_data`)
2. Reconcile the ShopWired `comparePrice` based on business rules (uniform selling price → highest RRP; otherwise null)
3. Orchestrate both selling price and retail price updates from a single controller endpoint

**Key insight**: ShopWired `comparePrice` is a product-level field (not per-variation), but our RRP is per-SKU. The reconciliation derives the correct product-level value from per-SKU data.

## Phase 0: Test ShopWired PUT comparePrice clearing

**Before any code changes**, manually test how `PUT products/{id}` handles comparePrice clearing:

```bash
# Test 1: Send comparePrice: 0
curl -X PUT .../products/{id} -d '{"comparePrice": 0}'

# Test 2: Send comparePrice: null  
curl -X PUT .../products/{id} -d '{"comparePrice": null}'

# Test 3: Send comparePrice: "" (empty string)
curl -X PUT .../products/{id} -d '{"comparePrice": ""}'
```

Document which approach clears the comparePrice (or if none work). `salePrice: 0` is known to be silently ignored by PUT (issue #308) — comparePrice may behave differently.

**This is a blocker** — the reconciliation's "remove comparePrice" path depends on the result.

## Architecture Overview

```
Controller (has productId + UpdatePriceCommand[])
    │
    ▼
UpdateProductPricesUseCase (NEW orchestrator)
    ├── splits commands: price/salePrice vs rrp
    ├── calls UpdateProductSellingPricesUseCase (RENAMED current)
    ├── calls UpdateProductRetailPricesUseCase (NEW)
    │       ├── writes RRP to catalog.product_extra_data per SKU
    │       └── calls ReconcileShopwiredComparePriceUseCase (NEW)
    │               ├── checks uniform selling price
    │               ├── calculates correct comparePrice (highest RRP or null)
    │               └── updates ShopWired via PUT if different
    ├── merges PriceUpdateResult from both
    └── returns combined PriceUpdateResult

CheckExpiredSalesUseCase → UpdateProductSellingPricesUseCase (direct, no orchestrator)
```

## Files Overview

### New Files

| File | Layer | Purpose |
|------|-------|---------|
| `database/migrations/..._create_catalog_product_extra_data_table.php` | — | Migration |
| `app/Domain/Catalog/Product/Exceptions/RequiredRelationNotLoadedException.php` | Domain | LogicException for unloaded relations |
| `app/Infrastructure/Catalog/Product/Models/ProductExtraDataModel.php` | Infra | Eloquent model |
| `app/Application/Contracts/Catalog/ProductExtraDataRepositoryInterface.php` | App | Repository contract (writes) |
| `app/Infrastructure/Catalog/Product/Repositories/EloquentProductExtraDataRepository.php` | Infra | Repository impl |
| `app/Domain/Catalog/Product/Commands/UpdateRetailPriceCommand.php` | Domain | Per-SKU RRP command |
| `app/Application/Catalog/RetailPricing/UseCases/UpdateProductRetailPricesUseCase.php` | App | DB write + reconciliation call |
| `app/Application/Shopwired/PricingUpdate/UseCases/ReconcileShopwiredComparePriceUseCase.php` | App | Derive + push comparePrice |
| `app/Application/Shopwired/PricingUpdate/UseCases/UpdateProductPricesUseCase.php` | App | New orchestrator |

### Modified Files

| File | Change |
|------|--------|
| `app/Domain/Catalog/Product/ValueObjects/Product.php` | Add `$skuRetailPrices`, `hasSingleSellingPrice()`, `resolveHighestRrp()` |
| `app/Domain/Catalog/Product/ValueObjects/Sku.php` | Add `deduplicate()` static method |
| `app/Infrastructure/Shopwired/Clients/PriceUpdateClient.php` | Remove `comparePrice` from `formatItem()` |
| `app/Infrastructure/Shopwired/Clients/ProductUpdateClient.php` | Add public `updateComparePrice()`, widen `updateProductField` type |
| `app/Application/Contracts/Shopwired/ProductUpdateClientInterface.php` | Add `updateComparePrice()` |
| `app/Infrastructure/Catalog/Product/Models/ProductModel.php` | Add `extraData` hasOne |
| `app/Infrastructure/Catalog/Product/Models/ProductVariationModel.php` | Add `extraData` hasOne |
| `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php` | Add `extraData` + `variations.extraData` to `EAGER_LOAD_RELATIONS` |
| `app/Infrastructure/Shopwired/Factories/ProductModelMapper.php` | Pass `skuRetailPrices` when building Product VO |
| `app/Domain/Catalog/Product/ValueObjects/ProductView.php` | Source `rrp` from `extraData->rrp` instead of `compare_price` |
| `app/Domain/Catalog/Product/ValueObjects/ProductVariationView.php` | Add `?Money $rrp` field sourced from variation's `extraData->rrp` |
| `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php` | Pass per-SKU RRP from `extraData` relation |
| `app/Infrastructure/Catalog/Product/Mappers/ProductVariationModelMapper.php` | Pass variation RRP from `extraData` relation |
| `app/Application/Shopwired/PricingUpdate/UseCases/UpdateProductSellingPricesUseCase.php` | RENAME from `UpdateProductPricesUseCase` |
| `app/Application/Shopwired/SaleManagement/UseCases/CheckExpiredSalesUseCase.php` | Update import to renamed use case |
| `app/Presentation/Http/Api/Controllers/ProductUpdateController.php` | Pass productId to orchestrator |
| `phpstan-complexity-baseline.neon` | Update class name references |

### Test Files

| File | Change |
|------|--------|
| `tests/.../UpdateProductSellingPricesUseCaseTest.php` | RENAME from existing test |
| `tests/.../UpdateProductPricesUseCaseTest.php` | New (orchestrator) |
| `tests/.../UpdateProductRetailPricesUseCaseTest.php` | New |
| `tests/.../ReconcileShopwiredComparePriceUseCaseTest.php` | New |
| `tests/.../UpdateRetailPriceCommandTest.php` | New |
| `tests/.../SkuTest.php` | Add `deduplicate()` tests |
| `tests/.../CheckExpiredSalesUseCaseTest.php` | Update import |
| `tests/.../RequiredRelationNotLoadedExceptionTest.php` | New |
| `tests/.../ProductHasSingleSellingPriceTest.php` | New |
| `tests/.../ProductResolveHighestRrpTest.php` | New |

---

## Phase 1: Foundation (Database + Domain)

### 1.1 Migration: `catalog.product_extra_data`

```sql
CREATE TABLE catalog.product_extra_data (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    sku VARCHAR(64) NOT NULL UNIQUE,
    rrp DECIMAL(10,6) NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

CREATE INDEX idx_product_extra_data_sku ON catalog.product_extra_data (sku);
```

Generic table — `rrp` is the first column, more can be added later.

**Data migration**: Seed existing RRP values from `shopwired.products` into the new table so no data is lost:

```sql
INSERT INTO catalog.product_extra_data (sku, rrp, created_at, updated_at)
SELECT sku, compare_price, NOW(), NOW()
FROM shopwired.products
WHERE sku IS NOT NULL AND compare_price IS NOT NULL;
```

This goes in the same migration's `up()` method, after the table creation. The `down()` just drops the table (seeded data is expendable since `compare_price` remains on the source table).

### 1.2 Eloquent Model: `ProductExtraDataModel`

**File**: `app/Infrastructure/Catalog/Product/Models/ProductExtraDataModel.php`

- Table: `catalog.product_extra_data`
- Casts: `rrp` → float (nullable)
- No relationships defined on this model (the reverse hasOne is on ProductModel/ProductVariationModel)

### 1.3 Domain Exception: `RequiredRelationNotLoadedException`

**File**: `app/Domain/Catalog/Product/Exceptions/RequiredRelationNotLoadedException.php`

A `\LogicException` subclass (programming error — code tried to access data that wasn't loaded). NOT a `DomainException` subclass (which extends RuntimeException for business rule violations).

```php
final class RequiredRelationNotLoadedException extends \LogicException
{
    public function __construct(
        public readonly string $relationName,
        public readonly string $className,
    ) {
        parent::__construct('Required relation not loaded');
    }

    /** @return array{relation: string, class: string} */
    public function context(): array
    {
        return [
            'relation' => $this->relationName,
            'class' => $this->className,
        ];
    }
}
```

Static message per project conventions. Dynamic data (`relationName`, `className`) in `context()` for Sentry grouping.

### 1.4 `hasSingleSellingPrice()` on Product domain VO

**File**: `app/Domain/Catalog/Product/ValueObjects/Product.php`

Add method to Product VO. Throws `RequiredRelationNotLoadedException` if variations are null (not loaded).

Logic reuses the same concept as `ProductViewMeta::resolveCanEditRrp()` but checks master price too (as the spec requires: "identical selling price across main product and all variations").

```php
/**
 * Whether the product has a uniform selling price across master + all variations.
 *
 * Variations with null price inherit the parent price (treated as same).
 *
 * @throws RequiredRelationNotLoadedException If variations not loaded
 */
public function hasSingleSellingPrice(): bool
{
    if ($this->variations === null) {
        throw new RequiredRelationNotLoadedException('variations', self::class);
    }

    if ($this->variations === []) {
        return true;
    }

    return \array_all(
        $this->variations,
        fn(ProductVariation $v): bool =>
            $v->price === null || $v->price === $this->price,
    );
}
```

### 1.5 `$skuRetailPrices` + `resolveHighestRrp()` on Product domain VO

**File**: `app/Domain/Catalog/Product/ValueObjects/Product.php`

Add new **optional constructor parameter** with default `null` (preserves all existing construction sites):

```php
/** @var array<string, ?float>|null Map of SKU → RRP (null = not loaded) */
public ?array $skuRetailPrices = null,
```

Add method:

```php
/**
 * Resolve the highest RRP across all SKUs in this product.
 *
 * Returns null if no SKU has an RRP set (= clear comparePrice).
 *
 * @throws RequiredRelationNotLoadedException If skuRetailPrices not loaded
 */
public function resolveHighestRrp(): ?float
{
    if ($this->skuRetailPrices === null) {
        throw new RequiredRelationNotLoadedException('skuRetailPrices', self::class);
    }

    $rrps = \array_filter(
        $this->skuRetailPrices,
        static fn(?float $rrp): bool => $rrp !== null,
    );

    return $rrps !== [] ? max($rrps) : null;
}
```

### 1.6 HasOne relationships on Eloquent models

**ProductModel** (`app/Infrastructure/Catalog/Product/Models/ProductModel.php`) — add:
```php
public function extraData(): HasOne
{
    return $this->hasOne(ProductExtraDataModel::class, 'sku', 'sku');
}
```

**ProductVariationModel** (`app/Infrastructure/Catalog/Product/Models/ProductVariationModel.php`) — add:
```php
public function extraData(): HasOne
{
    return $this->hasOne(ProductExtraDataModel::class, 'sku', 'sku');
}
```

### 1.7 Auto-load `extraData` via `EAGER_LOAD_RELATIONS`

**File**: `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php`

Update the constant:
```php
private const array EAGER_LOAD_RELATIONS = ['variations', 'extraData', 'variations.extraData'];
```

This means every `getProduct()`, `getProductByAnySku()`, `getProductsOnSale()`, `streamAll()` etc. automatically loads extra data. Zero new repository methods needed.

**File**: `app/Infrastructure/Shopwired/Factories/ProductModelMapper.php` (or wherever `mapModelToDomain` delegates)

When building the Product VO, build `skuRetailPrices` from loaded relations:
```php
$skuRetailPrices = self::buildSkuRetailPrices($model);
// Pass as named arg: skuRetailPrices: $skuRetailPrices
```

```php
private static function buildSkuRetailPrices(ProductModel $model): array
{
    $map = [];

    if ($model->sku !== null && $model->relationLoaded('extraData')) {
        $map[$model->sku] = $model->extraData?->rrp !== null
            ? (float) $model->extraData->rrp
            : null;
    }

    foreach ($model->variations as $variation) {
        if ($variation->sku !== null && $variation->relationLoaded('extraData')) {
            $map[$variation->sku] = $variation->extraData?->rrp !== null
                ? (float) $variation->extraData->rrp
                : null;
        }
    }

    return $map;
}
```

### 1.8 Domain Command: `UpdateRetailPriceCommand`

**File**: `app/Domain/Catalog/Product/Commands/UpdateRetailPriceCommand.php`

```php
final readonly class UpdateRetailPriceCommand
{
    /**
     * @param Sku $sku SKU to set RRP for
     * @param Money $rrp RRP value (Money::inclusive(0) = clear)
     */
    public function __construct(
        public Sku $sku,
        public Money $rrp,
    ) {}
}
```

### 1.9 Repository Interface (writes)

**File**: `app/Application/Contracts/Catalog/ProductExtraDataRepositoryInterface.php`

```php
interface ProductExtraDataRepositoryInterface
{
    /**
     * Upsert RRP for a SKU. Null clears the RRP value.
     *
     * @throws DatabaseOperationFailedException
     * @throws ExternalServiceUnavailableException
     */
    public function upsertRrp(Sku $sku, ?Money $rrp): void;
}
```

### 1.10 Repository Implementation

**File**: `app/Infrastructure/Catalog/Product/Repositories/EloquentProductExtraDataRepository.php`

Uses `updateOrCreate` on `ProductExtraDataModel` keyed by `sku`.

### 1.11 `Sku::deduplicate()`

**File**: `app/Domain/Catalog/Product/ValueObjects/Sku.php`

Add static method for value-based deduplication (same as previous plan — keeps first occurrence).

---

## Phase 2: Remove comparePrice from batch POST

### 2.1 PriceUpdateClient

**File**: `app/Infrastructure/Shopwired/Clients/PriceUpdateClient.php:157-159`

Remove the comparePrice block from `formatItem()`:
```php
// DELETE:
if ($command->rrp !== null) {
    $item['comparePrice'] = $command->rrp->toGross();
}
```

---

## Phase 3: ShopWired comparePrice update via PUT

### 3.1 Widen `updateProductField` type

**File**: `app/Infrastructure/Shopwired/Clients/ProductUpdateClient.php:75`

Change:
```php
private function updateProductField(int $productId, string $fieldName, array $data): void
```
To:
```php
private function updateProductField(int $productId, string $fieldName, array|float|int|null $data): void
```

Update the `@param` docblock accordingly.

### 3.2 Add `updateComparePrice()` public method

**File**: `app/Infrastructure/Shopwired/Clients/ProductUpdateClient.php`

```php
/**
 * Update or clear the comparePrice (RRP) on a ShopWired product.
 *
 * @param int $productId ShopWired product external ID
 * @param float|null $comparePrice New comparePrice (null = clear, based on Phase 0 testing)
 *
 * @throws ResourceNotAvailableException
 * @throws InvalidApiRequestException
 * @throws AuthenticationExpiredException
 * @throws ExternalServiceUnavailableException
 */
public function updateComparePrice(int $productId, ?float $comparePrice): void
{
    $this->updateProductField($productId, 'comparePrice', $comparePrice ?? 0);
    // ^^ The clear value (0 vs null) depends on Phase 0 test results
}
```

### 3.3 Add to interface

**File**: `app/Application/Contracts/Shopwired/ProductUpdateClientInterface.php`

Add `updateComparePrice(int $productId, ?float $comparePrice): void` with `@throws` declarations.

---

## Phase 4: Reconciliation Use Case

### 4.1 `ReconcileShopwiredComparePriceUseCase`

**File**: `app/Application/Shopwired/PricingUpdate/UseCases/ReconcileShopwiredComparePriceUseCase.php`

**Dependencies**: `ProductRepositoryInterface`, `ProductUpdateClientInterface`, `LoggerInterface`

Works entirely with domain VOs — loads via `ProductRepositoryInterface::getProduct(IntId)` (which now auto-loads `extraData` + `variations.extraData` via `EAGER_LOAD_RELATIONS`).

**Signature**:
```php
public function execute(IntId $productId): void
```

**Flow**:
1. `$product = $this->productRepository->getProduct($productId)` — returns Product VO with `variations` and `skuRetailPrices` populated
2. Call `$product->hasSingleSellingPrice()`:
   - If `false` → target comparePrice is `null`
   - If `true` → target comparePrice is `$product->resolveHighestRrp()`
3. Compare target with `$product->comparePrice` (current value from DB)
4. If target === current → no-op, return
5. If different → call `$this->productUpdateClient->updateComparePrice($product->id, $targetComparePrice)`
6. Log the reconciliation outcome

All business logic (`hasSingleSellingPrice`, `resolveHighestRrp`) lives on the Product domain VO. The use case just orchestrates.

---

## Phase 5: Retail Price Write Use Case

### 5.1 `UpdateProductRetailPricesUseCase`

**File**: `app/Application/Catalog/RetailPricing/UseCases/UpdateProductRetailPricesUseCase.php`

**Dependencies**: `ProductExtraDataRepositoryInterface`, `ReconcileShopwiredComparePriceUseCase`, `LoggerInterface`

**Signature**:
```php
public function execute(IntId $productId, array $commands): PriceUpdateResult
```

**Flow**:
1. For each `UpdateRetailPriceCommand`:
   - Resolve RRP value: `$cmd->rrp->isZero() ? null : $cmd->rrp` (zero-means-clear)
   - Call `$repo->upsertRrp($cmd->sku, $resolvedRrp)`
   - Track succeeded SKUs
   - Catch `DatabaseOperationFailedException|ExternalServiceUnavailableException` per-item → `FailedPriceUpdateResult`
2. Call `$this->reconcileUseCase->execute($productId)` (best-effort — catch + log on failure)
3. Return `PriceUpdateResult` with succeeded count and any failures

---

## Phase 6: Update View VOs to use per-SKU RRP

### 6.1 `ProductView` — source RRP from `extraData`

**File**: `app/Domain/Catalog/Product/ValueObjects/ProductView.php`

Currently `rrp` is sourced from `compare_price` (the product-level ShopWired field). Change to source from the master SKU's `extraData->rrp` instead. The `rrp` constructor param stays `?float` — the assembler just passes a different source value.

**File**: `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php`

Change line ~77:
```php
// Before:
rrp: $model->compare_price,

// After:
rrp: $model->extraData?->rrp,
```

Since `extraData` is now auto-loaded via `EAGER_LOAD_RELATIONS`, this just works.

### 6.2 `ProductVariationView` — add `?Money $rrp` field

**File**: `app/Domain/Catalog/Product/ValueObjects/ProductVariationView.php`

Add new constructor parameter + property:
```php
?float $rrp,
// In constructor body:
$this->rrp = Money::nonZeroOrNull($rrp, $taxType);
```

**File**: `app/Infrastructure/Catalog/Product/Mappers/ProductVariationModelMapper.php`

Pass `rrp` from the variation's extra data:
```php
rrp: $model->extraData?->rrp,
```

**Note**: `ProductViewMeta::resolveCanEditRrp()` (read path / UI flag) and `Product::hasSingleSellingPrice()` (write path / reconciliation) are intentionally separate. Do not align them.

---

## Phase 7: Rename + Orchestrator

### 7.1 Rename current use case

`git mv` the file:
```
app/.../UseCases/UpdateProductPricesUseCase.php → UpdateProductSellingPricesUseCase.php
```

- Rename class inside the file
- Update all internal references (namespace, class name)
- Update `CheckExpiredSalesUseCase` import
- Update `CheckExpiredSalesUseCaseTest` import
- Update `phpstan-complexity-baseline.neon` paths
- Rename test file accordingly

### 7.2 New Orchestrator: `UpdateProductPricesUseCase`

**File**: `app/Application/Shopwired/PricingUpdate/UseCases/UpdateProductPricesUseCase.php`

**Dependencies**:
- `UpdateProductSellingPricesUseCase`
- `UpdateProductRetailPricesUseCase`

**Signature** (accepts productId from controller):
```php
public function execute(
    IntId $productId,
    array $skuUpdates,
    ?SaleSettings $saleSettings = null,
): PriceUpdateResult
```

**Flow**:
1. Partition `$skuUpdates` (list of `UpdatePriceCommand`):
   - Commands with `price !== null || salePrice !== null` → selling price path
   - Commands with `rrp !== null` → retail price path (extract into `UpdateRetailPriceCommand`)
   - A command can appear in both lists
2. Call selling prices use case (if any selling price commands):
   ```php
   $sellingResult = $this->sellingPricesUseCase->execute($sellingCommands, $saleSettings);
   ```
3. Call retail prices use case (if any RRP commands):
   ```php
   $retailResult = $this->retailPricesUseCase->execute($productId, $rrpCommands);
   ```
4. Merge results:
   ```php
   return PriceUpdateResult::merge($sellingResult, $retailResult);
   ```
   Or manually combine totals, succeeded counts, and failure lists using `Sku::deduplicate()` for updatedSkus.

### 7.3 Update Controller

**File**: `app/Presentation/Http/Api/Controllers/ProductUpdateController.php`

The controller already has `string $productId` from the route. Update `updatePrices()`:

```php
$result = $this->priceUseCase->execute(
    IntId::from((int) $productId),
    $commands,
    $data->saleSettings?->toDomain(),
);
```

The injected type is still `UpdateProductPricesUseCase` — now the orchestrator. Import doesn't change.

### 7.4 PriceUpdateResult merge helper

Add a static method to `PriceUpdateResult` (or handle inline in orchestrator):

```php
public static function merge(?self $a, ?self $b): self
```

Combines totals, succeeded (deduplicated), skipped, and failure lists from both results.

---

## Phase 8: Tests

### 8.1 Rename existing test
`git mv` `UpdateProductPricesUseCaseTest.php` → `UpdateProductSellingPricesUseCaseTest.php`

### 8.2 New test: Orchestrator
Test command partitioning, delegation to sub-use-cases, result merging. Mock both sub-use-cases.

### 8.3 New test: UpdateProductRetailPricesUseCase
Test DB upsert per-SKU, zero-means-clear, reconciliation called, per-item error handling.

### 8.4 New test: ReconcileShopwiredComparePriceUseCase
Test: uniform price → highest RRP sent; non-uniform → null sent; no change → no API call; API failure logged.

### 8.5 Sku::deduplicate tests
Empty, no-dupes, with-dupes (first occurrence preserved).

### 8.6 UpdateRetailPriceCommand tests
Construction, value access.

### 8.7 Product::hasSingleSellingPrice tests
- Throws `RequiredRelationNotLoadedException` when `variations` is null
- Returns `true` with empty variations
- Returns `true` when all variation prices match master
- Returns `true` when variations have null price (inherit)
- Returns `false` when prices differ

### 8.8 Product::resolveHighestRrp tests
- Throws `RequiredRelationNotLoadedException` when `skuRetailPrices` is null
- Returns null when all RRPs are null (= clear comparePrice)
- Returns highest value across multiple SKUs
- Returns single RRP when only one set

### 8.9 RequiredRelationNotLoadedException tests
Construction, context values.

---

## Edge Cases

| Scenario | Behaviour |
|----------|-----------|
| RRP-only update (no price/salePrice) | Selling prices use case skipped, retail prices written to DB, reconciliation runs |
| Price-only update (no RRP) | Retail prices use case skipped, selling prices updated as before |
| Mixed update (price + RRP) | Both paths execute, results merged |
| Uniform price → set comparePrice | Reconciliation sends highest RRP to ShopWired |
| Non-uniform prices → clear comparePrice | Reconciliation sends null/0 to ShopWired (per Phase 0 testing) |
| RRP cleared (Money::inclusive(0)) | DB sets rrp=null, reconciliation recalculates (may clear comparePrice) |
| No RRP data exists for any SKU | Reconciliation derives null → clears comparePrice if currently set |
| Reconciliation fails (API error) | Logged as warning, does NOT affect per-SKU result (DB write succeeded) |
| CheckExpiredSalesUseCase | Calls renamed `UpdateProductSellingPricesUseCase` directly — no RRP involvement |

## Verification

1. Phase 0: Manual ShopWired PUT test for comparePrice clearing — **do first**
2. `php artisan migrate` — verify table created
3. `make fix && make lint` — code style + static analysis
4. `make test` — all tests pass
5. **Manual test**: PUT `/api/products/20181639/prices` with RRP change:
   - Verify RRP written to `catalog.product_extra_data`
   - Verify reconciliation calculates correct comparePrice
   - Verify ShopWired updated (check via GET)
   - Verify `Product price update completed` with `succeeded: 1`
