# Plan: Product Cost Price Update + Suppliers Include

## Context

The frontend needs two new capabilities:
1. **Update a product's cost price** via a dedicated PUT endpoint, targeting a specific supplier's purchase price in Linnworks (and syncing to ShopWired).
2. **View suppliers** on a product detail page via the existing `?include=` mechanism, showing which suppliers provide the product and at what cost.

These are independent features that share the supplier data model but have no code dependencies on each other.

---

## Feature 1: PUT /api/products/{sku}/cost-price

### Route & Controller

**Modify `routes/api.php`** — Add inside the authenticated consumer group (after line 148):
```php
Route::put('products/{sku}/cost-price', [ProductUpdateController::class, 'updateCostPrice']);
```
- Uses `{sku}` (string) — no `whereNumber` constraint needed, won't collide with `{productId}` routes which have `whereNumber`.

**Modify `app/Presentation/Http/Api/Controllers/ProductUpdateController.php`** — Add `updateCostPrice` method + inject `UpdateCostPriceUseCase`:
```php
public function updateCostPrice(string $sku, UpdateCostPriceRequestDTO $data): JsonResponse
{
    $this->costPriceUseCase->execute($data->toCommand($sku));
    return new JsonResponse(null, Response::HTTP_NO_CONTENT);
}
```

### Request DTO

**New: `app/Presentation/Http/Api/DTOs/UpdateCostPriceRequestDTO.php`**

Spatie LaravelData DTO accepting camelCase JSON body `{ "costPrice": 5.99, "supplierName": "Acme Corp" }`:
- `costPrice`: float, `Min(0)`, required
- `supplierName`: string, required
- `toCommand(string $sku): UpdateCostPriceCommand` — wraps into domain command with `Sku::fromString()` (validates user input), `Money::exclusive()`

### Domain Command

**New: `app/Domain/Catalog/Product/Commands/UpdateCostPriceCommand.php`**
```php
final readonly class UpdateCostPriceCommand
{
    public function __construct(
        public Sku $sku,
        public Money $costPrice,        // Money::exclusive() — cost prices are always ex-VAT
        public string $supplierName,    // GUID resolution is infrastructure's job
    ) {}
}
```

### Use Case

**New: `app/Application/Catalog/UseCases/UpdateCostPriceUseCase.php`**

Thin orchestrator (follows `UpdateProductFieldsUseCase` pattern):
1. Call `InventoryUpdateClientInterface::updateSupplierPurchasePrice($command->sku, $command->supplierName, $command->costPrice)` — updates Linnworks via API
2. Update the local database (`stock_item_suppliers.purchase_price`) to reflect the change immediately without waiting for the next full sync

**No ShopWired sync** — ShopWired's cost price field is unused and not displayed anywhere.

Dependencies: `InventoryUpdateClientInterface`, `StockItemRepositoryInterface` (or a focused update method), `LoggerInterface`

No try-catch — exceptions bubble to the global handler per Application layer conventions.

### Interface Method

**Modify `app/Application/Contracts/Linnworks/InventoryUpdateClientInterface.php`** — Add:
```php
/**
 * Update purchase price for an existing supplier link on a stock item.
 *
 * The implementation resolves supplier name → GUID internally.
 *
 * @param Sku|Guid $identifier SKU (resolved internally) or stockItemId
 * @param string $supplierName Human-readable supplier name (resolved to GUID internally)
 * @param Money $purchasePrice New purchase price (ex-VAT)
 *
 * @throws ResourceNotFoundException When stock item or supplier not found
 * @throws InvalidApiRequestException When parameters invalid
 * @throws InvalidApiResponseException When API response malformed
 * @throws AuthenticationExpiredException When credentials invalid
 * @throws ExternalServiceUnavailableException When API unavailable
 */
public function updateSupplierPurchasePrice(Sku|Guid $identifier, string $supplierName, Money $purchasePrice): void;
```

### Infrastructure Implementation

**Modify `app/Infrastructure/Linnworks/Clients/InventoryUpdateClient.php`** — Add implementation:

1. Resolve `$identifier` → `$stockItemId` via existing `$this->inventoryClient->resolveStockItemId($identifier)`
2. Resolve `$supplierName` → `Guid` via `$this->inventoryClient->getSuppliers()` — find matching supplier by name, throw `ResourceNotFoundException('Linnworks', 'supplier', $supplierName)` if not found. This uses the live Linnworks API (`/api/Inventory/GetSuppliers`) as source of truth, keeping the client focused on API operations.
3. Build payload mirroring `createSupplierStat`:
   ```php
   $supplierStat = [
       'StockItemId' => $stockItemId->value,
       'SupplierID'  => $supplierGuid->value,
       'PurchasePrice' => $purchasePrice->toNet(),
   ];
   ```
4. Call `$this->transport->postFormParams('/api/Inventory/UpdateStockSupplierStat', ['itemSuppliers' => [$supplierStat]])`

No new dependencies needed — `InventoryClientInterface` (already injected) provides `getSuppliers()`.

### Exception Handling

No new exceptions needed. Existing `ResourceNotFoundException` covers both "stock item not found" and "supplier not found" cases — the `resourceType` field distinguishes them. The global exception handler already maps this to HTTP 404.

---

## Feature 2: Suppliers Include on GET /api/products/{productId}

### Domain VO

**New: `app/Domain/Catalog/Product/ValueObjects/ProductSupplier.php`**
```php
final readonly class ProductSupplier
{
    public function __construct(
        public string $supplierName,
        public ?float $purchasePrice,
        public bool $isDefault,
    ) {}
}
```

Lives in the Catalog domain (not Inventory) — this is a product-centric projection of supplier data. Uses `?float` for `purchasePrice` since this is a read-only projection matching how `costPrice` is handled as a float primitive in `ProductView`.

### Enum Case

**Modify `app/Domain/Catalog/Product/Enums/ProductInclude.php`** — Add:
```php
case Suppliers = 'suppliers';
```

### ProductView VO

**Modify `app/Domain/Catalog/Product/ValueObjects/ProductView.php`** — Add constructor parameter after `$freeDelivery`:
```php
/** @var list<ProductSupplier>|null */
public ?array $suppliers = null,
```
Nullable with default = backward-compatible, `null` means "not loaded".

### Supplier Factory (lazy-loaded, O(1) lookup)

**New: `app/Infrastructure/Catalog/Product/Factories/ProductSupplierFactory.php`**

Follows the `CustomFieldFactory` lazy-load pattern — loads ALL supplier data once on first access, then serves O(1) lookups by SKU:

```php
final class ProductSupplierFactory
{
    /** @var array<string, list<ProductSupplier>>|null SKU → suppliers map, null = not loaded */
    private ?array $suppliersBySku = null;

    public function __construct(
        private readonly DatabaseGatewayInterface $gateway,
    ) {}

    /**
     * @return list<ProductSupplier>
     */
    public function getByProductSku(string $sku): array
    {
        return $this->supplierMap()[$sku] ?? [];
    }

    /**
     * Lazy-load all supplier data, keyed by SKU.
     * @return array<string, list<ProductSupplier>>
     */
    private function supplierMap(): array
    {
        if ($this->suppliersBySku === null) {
            // Single query: join stock_item_suppliers → stock_items, group by SKU
            $this->suppliersBySku = $this->loadAll();
        }
        return $this->suppliersBySku;
    }
}
```

Register as `scoped()` in `ShopwiredServiceProvider` (fresh per request/job, like `ProductViewAssembler`).

No interface needed — this is an infrastructure-internal factory (not a cross-layer contract), same as `CustomFieldFactory`.

### Assembler Integration

**Modify `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php`**:

1. Add `ProductSupplierFactory` as constructor dependency
2. Add `resolveSuppliers()` method:
   ```php
   private function resolveSuppliers(ProductViewModel $model, array $includes): ?array
   {
       if (!\in_array(ProductInclude::Suppliers, $includes, true)) {
           return null;
       }
       if ($model->sku === null || $model->sku === '') {
           return [];
       }
       return $this->supplierFactory->getByProductSku($model->sku);
   }
   ```
3. Pass result to `ProductView` constructor: `suppliers: $this->resolveSuppliers($model, $includes)`

### Resource Serialization

**Modify `app/Presentation/Http/Api/Resources/ProductDetailResource.php`** — Add block after sale_settings:
```php
if ($result->hasInclude(ProductInclude::Suppliers) && $product->suppliers !== null) {
    $data['suppliers'] = \array_map(
        static fn(ProductSupplier $s): array => [
            'supplier_name' => $s->supplierName,
            'purchase_price' => $s->purchasePrice,
            'is_default' => $s->isDefault,
        ],
        $product->suppliers,
    );
}
```

### Request DTOs

`ShowProductRequestDTO::allowedIncludes()` already returns `ProductInclude::values()` — the new `Suppliers` case is automatically available.

`ListProductsRequestDTO::allowedIncludes()` — the factory pattern eliminates the N+1 concern (single query, O(1) lookups). Adding `Suppliers` to the list endpoint allowlist is now safe if desired.

### Service Provider

Register `ProductSupplierFactory` as `scoped()` in `ShopwiredServiceProvider` (alongside `ProductViewAssembler` which is also scoped). This ensures fresh data per request/job.

---

## Files Summary

| Action | File |
|--------|------|
| **New** | `app/Domain/Catalog/Product/Commands/UpdateCostPriceCommand.php` |
| **New** | `app/Domain/Catalog/Product/ValueObjects/ProductSupplier.php` |
| **New** | `app/Application/Catalog/UseCases/UpdateCostPriceUseCase.php` |
| **New** | `app/Infrastructure/Catalog/Product/Factories/ProductSupplierFactory.php` |
| **New** | `app/Presentation/Http/Api/DTOs/UpdateCostPriceRequestDTO.php` |
| **Modify** | `app/Application/Contracts/Linnworks/InventoryUpdateClientInterface.php` — add `updateSupplierPurchasePrice` |
| **Modify** | `app/Infrastructure/Linnworks/Clients/InventoryUpdateClient.php` — implement with API-based supplier resolution |
| **Modify** | `app/Application/Contracts/Linnworks/StockItemRepositoryInterface.php` — add `updateSupplierPurchasePrice` for local DB |
| **Modify** | `app/Infrastructure/Linnworks/Repositories/EloquentStockItemRepository.php` — implement local DB update |
| **Modify** | `app/Domain/Catalog/Product/Enums/ProductInclude.php` — add `Suppliers` case |
| **Modify** | `app/Domain/Catalog/Product/ValueObjects/ProductView.php` — add `?array $suppliers` |
| **Modify** | `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php` — add `resolveSuppliers` |
| **Modify** | `app/Presentation/Http/Api/Controllers/ProductUpdateController.php` — add `updateCostPrice` |
| **Modify** | `app/Presentation/Http/Api/Resources/ProductDetailResource.php` — serialize suppliers |
| **Modify** | `routes/api.php` — add PUT route |
| **Modify** | `app/Providers/ShopwiredServiceProvider.php` — register `ProductSupplierFactory` as `scoped()` |

## Implementation Order

1. **Feature 2 first** (suppliers include) — purely additive read path, no external API calls, easy to verify
2. **Feature 1 second** (cost price update) — write path with Linnworks API integration

## Verification

1. **Lint**: `make lint` — PHPStan, Pint, PHPArkitect, Deptrac all pass
2. **Tests**: `make test` — existing tests still pass
3. **Manual — suppliers include**: `GET /api/products/{id}?include=suppliers` returns supplier array with name, purchase_price, is_default
4. **Manual — cost price update**: `PUT /api/products/{sku}/cost-price` with `{ "costPrice": 5.99, "supplierName": "..." }` returns 204
5. **Negative cases**: Invalid supplier name returns 404, missing fields return 422
