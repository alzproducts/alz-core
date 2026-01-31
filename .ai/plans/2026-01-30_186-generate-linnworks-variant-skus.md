# Plan: Generate Linnworks Items from ShopWired Variations

## Overview

Create a command that takes a ShopWired product ID and a Linnworks template SKU, then generates new Linnworks inventory items for each SKU-less variation.

**Business Use Case**: Staff adds 30-40 new variations to a product in ShopWired → needs an easy way to "generate" all variations in Linnworks automatically.

## Input/Output

**Command Input:**
- `int $productId` — ShopWired product external ID
- `string $baseCopySku` — Existing Linnworks SKU to use as template

**Execution Flow:**
1. Fetch ShopWired product + variations
2. Resolve inherited prices (null → parent, but 0.00 stays)
3. Filter to variations WITHOUT SKUs
4. Fetch Linnworks template by `baseCopySku`
5. For each SKU-less variation → create in Linnworks → write SKU back to ShopWired
6. Refresh local ProductModel from API

## Data Mapping

| Field | Source |
|-------|--------|
| SKU | Linnworks `getNewItemNumber()` |
| Title | `{parent.name} - {option values space-separated}` |
| Category | Template `StockItemFull.categoryId` |
| Supplier | Template `StockItemFull.getDefaultSupplier()` |
| Price | Variation (resolved) `price` |
| Cost Price | Variation (resolved) `costPrice` |
| Barcode | Variation `gtin` |
| MPN | Variation `mpn` |
| Image | Variation `imageIndex` → parent `images[index]` URL |
| Extended Property | `ShopID: {variation.external_id}` |

---

## Implementation Phases

### Phase 1: Domain Layer — Reusable Resolvers

**1.1 Price Resolver** (reusable across app)
- Location: `App\Domain\Catalog\Product\Services\VariationPriceResolver`
- Input: `ProductVariation`, parent prices (`?float $parentPrice, ?float $parentCostPrice, ?float $parentSalePrice`)
- Output: `ResolvedVariationPrices` VO with final values
- Logic: `null` → inherit parent, `0.00` → keep as 0.00

**1.2 Image Resolver** (reusable across app)
- Location: `App\Domain\Catalog\Product\Services\VariationImageResolver`
- Input: `ProductVariation`, parent `list<ProductImage>`
- Output: `?ProductImage` (null if no image_index)

### Phase 2: Linnworks Infrastructure — New Endpoints

**2.1 Read Methods** (`InventoryClientInterface`)
```php
public function getStockItemFullBySku(string $sku): StockItemFull;
```

**2.2 Write Methods** (`InventoryUpdateClientInterface`)
```php
public function addInventoryItem(AddInventoryItemCommand $command): void; // returns 204, no body
public function createSupplierStat(CreateSupplierStatCommand $command): void;
public function addExtendedProperty(string $stockItemId, string $name, string $value): void;
public function addImage(string $stockItemId, string $imageUrl): void;
public function deleteInventoryItem(string $stockItemId): void; // for rollback
```

**Note:** `addInventoryItem` returns 204 (no body). After creation, call `getStockItemBySku(sku)` to retrieve the `stockItemId` for subsequent operations.

**2.3 Command DTOs** (`App\Domain\Inventory\Commands\`)
- `AddInventoryItemCommand` — SKU, title, categoryId, price, costPrice, barcode, mpn
- `CreateSupplierStatCommand` — stockItemId, supplierId, costPrice, supplierCode (from template)

### Phase 3: ShopWired Infrastructure — Product/Variation Lookup Refactor

**3.1 Polymorphic Product Identifier** (new pattern)
- Create `App\Domain\ValueObjects\IntId` — Integer identifier VO for external IDs
- Update `UpdateBasicProductCommand` to accept `string|IntId` as identifier
- Refactor `ProductRepositoryInterface::getBasicProductBySku()` → `getBasicProduct(string|IntId $identifier)`
- Internal logic: string = SKU lookup, IntId = direct ID lookup

**3.2 Single Product Fetch** (already exists)
- `ProductClientInterface::getProductById(int $id): Product` ✅ exists
- Need to call repository to persist refreshed data after fetch

### Phase 3.5: Domain/Infrastructure — SKU Generation Lock

**Problem:** Race condition when multiple processes generate SKUs simultaneously → duplicate SKUs.

**Solution:** Distributed lock around SKU generation + item creation.

**3.5.1 Application Interface** (`App\Application\Contracts\LockManagerInterface`)
```php
interface LockManagerInterface
{
    /**
     * @template T
     * @param string $name Lock identifier
     * @param int $timeout Maximum seconds to wait for lock acquisition
     * @param callable(): T $callback
     * @return T
     * @throws LockAcquisitionException
     */
    public function withLock(string $name, int $timeout, callable $callback): mixed;
}
```

**3.5.2 Domain Exception** (`App\Domain\Exceptions\LockAcquisitionException`)

**3.5.3 Infrastructure Implementation** (`App\Infrastructure\Locking\CacheLockManager`)
- Uses Laravel `Cache::lock()` with Redis backend
- Hold time = `timeout + 10` seconds (slightly longer than wait time)
- Catches `LockTimeoutException`, translates to domain exception

**3.5.4 Update Existing** (`UpdateSkuUseCase`)
- Inject `LockManagerInterface`
- Wrap SKU generation + Linnworks update in lock

**Lock Names:**
- `sku-generation` — Used by both UseCases

---

### Phase 4: Application Layer — UseCase

**4.1 Command** (`App\Application\Inventory\Commands\`)
```php
final readonly class GenerateVariantSkusCommand {
    public function __construct(
        public int $productId,
        public string $baseCopySku,
    ) {}
}
```

**4.2 UseCase** (`App\Application\Inventory\UseCases\`)
```php
final readonly class GenerateVariantSkusUseCase {
    // Dependencies: LinnworksClient, ShopWiredProductClient,
    //               BasicProductUpdateClient, VariationPriceResolver,
    //               VariationImageResolver

    public function execute(GenerateVariantSkusCommand $command): GenerateVariantSkusResult;
}
```

**4.3 Result VO**
```php
final readonly class GenerateVariantSkusResult {
    public int $processed;
    public int $skipped; // already had SKU
    public int $created;
}
```

**4.4 Per-Variation Transaction Flow:**
```
LOCKED SECTION (sku-generation lock):
  1. Generate new SKU from Linnworks (getNewItemNumber)
  2. Create inventory item (addInventoryItem → 204)
  3. Fetch stockItemId (getStockItemBySku)
END LOCK

4. Link supplier (createSupplierStat)
5. Add extended property (addExtendedProperty)
6. Add image if available (addImage)
7. Update ShopWired variation with new SKU
   ↳ If ANY step 4-7 fails → deleteInventoryItem to rollback, skip variation
8. After all variations → Refresh local ProductModel
```

**Why lock only steps 1-3?** The race condition is between SKU generation and item creation. Once the item exists in Linnworks with that SKU, no other process can claim it. Steps 4-7 are idempotent or can be rolled back.

### Phase 5: Presentation Layer — Console Command

**5.1 Command** (`App\Presentation\Console\Commands\`)
```php
class GenerateVariantSkusCommand extends Command {
    protected $signature = 'inventory:generate-variant-skus
                            {productId : ShopWired product ID}
                            {baseCopySku : Linnworks template SKU}';
}
```

---

## Critical Files to Modify/Create

| Layer | File | Action |
|-------|------|--------|
| **Domain** | `Catalog/Product/Services/VariationPriceResolver.php` | Create |
| **Domain** | `Catalog/Product/Services/VariationImageResolver.php` | Create |
| **Domain** | `Inventory/Commands/AddInventoryItemCommand.php` | Create |
| **Domain** | `Inventory/Commands/CreateSupplierStatCommand.php` | Create |
| **Domain** | `Exceptions/LockAcquisitionException.php` | Create |
| **Application** | `Contracts/LockManagerInterface.php` | Create |
| **Domain** | `ValueObjects/IntId.php` | Create (polymorphic identifier) |
| **Domain** | `Catalog/Product/Commands/UpdateBasicProductCommand.php` | Modify (accept `string\|IntId`) |
| **Application** | `Contracts/Shopwired/ProductRepositoryInterface.php` | Modify (rename method, accept `string\|IntId`) |
| **Infrastructure** | `Shopwired/Repositories/EloquentProductRepository.php` | Modify (polymorphic lookup) |
| **Application** | `Contracts/Linnworks/InventoryClientInterface.php` | Modify (add `getStockItemFullBySku`) |
| **Application** | `Contracts/Linnworks/InventoryUpdateClientInterface.php` | Modify (add 5 write methods) |
| **Application** | `Inventory/Commands/GenerateVariantSkusCommand.php` | Create |
| **Application** | `Inventory/UseCases/GenerateVariantSkusUseCase.php` | Create |
| **Application** | `Inventory/UseCases/UpdateSkuUseCase.php` | Modify (add locking) |
| **Application** | `Inventory/Results/GenerateVariantSkusResult.php` | Create |
| **Infrastructure** | `Linnworks/Clients/InventoryClient.php` | Modify (add `getStockItemFullBySku`) |
| **Infrastructure** | `Linnworks/Clients/InventoryUpdateClient.php` | Modify (add 5 write methods) |
| **Infrastructure** | `Locking/CacheLockManager.php` | Create |
| **Presentation** | `Console/Commands/GenerateVariantSkusCommand.php` | Create |

---

## Verification Plan

1. **Unit Tests**: Price resolver, image resolver with edge cases (null, 0.00, missing index)
2. **Integration Tests**: Linnworks endpoint mocks for each new API call
3. **Manual Testing**:
   - Create test product in ShopWired with 3 SKU-less variations
   - Run command with valid template SKU
   - Verify: items created in Linnworks, SKUs written back to ShopWired variations
   - Test rollback: disconnect network mid-operation, verify partial items cleaned up

---

## Questions Resolved

- ✅ SKU sync back to ShopWired: Yes
- ✅ Error handling: Transactional per-variation with rollback
- ✅ Title format: `{parent name} - {values space-separated}`
- ✅ Execution: Synchronous (re-runnable, skips completed variations)
- ✅ Image handling: Skip if `image_index` is null
- ✅ Variation fields: price, cost_price (resolved), gtin, mpn
- ✅ Extended property: `ShopID: {external_id}`
- ✅ Final step: Refresh ProductModel from API

---

## Known Blockers & Issues (Discovered 2026-01-31)

### BLOCKER: Linnworks Soft-Delete SKU Collision

**Problem**: Linnworks soft-deletes items. `AddInventoryItem` with a SKU matching a soft-deleted item returns 204 success but doesn't actually create.

**Why critical**: `GetNewItemNumber` may return a SKU that was previously deleted. `GetStockItemIdsBySKU` doesn't return soft-deleted items, so we can't detect the collision.

**Solution required**: Implement Linnworks SQL query endpoint to check raw database (including soft-deleted). Legacy codebase has working implementation.

**Handoff**: `.ai/handoffs/linnworks-sql-query-analysis.md`

### BLOCKER: CreateStockSupplierStat Silent Failure

**Problem**: Returns HTTP 204 but doesn't create supplier linkage. Verified via `GetStockItemsFullByIds` showing `Suppliers: []`.

**Solution required**: Post-creation verification — check `Suppliers` array is non-empty after call, throw exception if empty to trigger rollback.

### Fixed Issues

| Issue | Fix | Commit |
|-------|-----|--------|
| Image index off-by-one (1-based vs 0-based) | Subtract 1 from imageIndex | 94d2bbc |
| GetStockItemsFullByIds doesn't support `Pricing` DataRequirement | Separate constant | 257898a |
| isVariationParent not returned by GetStockItemsFullByIds | Made nullable | 257898a |
| AddImageToInventoryItem double-wrapping | Removed inner wrapper | 257898a |
| TaxRate -1 (int) vs -1.0 (float) | Use float | 257898a |
| ShopWired cost price sentinel values (-1, 0) | VariationPriceResolver handles | 257898a |

### To Discuss (Future Improvements)

1. **Validation of data with regards to user input** — Should we validate command inputs more strictly? What happens with invalid product IDs or template SKUs?

2. **Command for refreshing ShopWired entities** — Need a way to refresh local database from ShopWired API before running commands (variation IDs can change between syncs).

3. **Adding logging to APIs** — Should Linnworks transport log all responses for debugging? Currently only logs errors. Would help diagnose silent failures like CreateStockSupplierStat.

---

## Implementation Order (Suggested)

1. **IntId VO + Polymorphic lookup refactor** — Create IntId, update UpdateBasicProductCommand, refactor getBasicProduct
2. **LockManager** — Interface + CacheLockManager + Exception + ServiceProvider binding
3. **Update UpdateSkuUseCase** — Add locking (validates locking works before new feature)
4. **Domain resolvers** — VariationPriceResolver, VariationImageResolver (testable in isolation)
5. **Linnworks read endpoint** — `getStockItemFullBySku`
6. **Linnworks write endpoints** — One at a time with tests (add, delete, supplier, EP, image)
7. **Application UseCase** — GenerateVariantSkusUseCase with integration test mocks
8. **Console command** — Manual verification
