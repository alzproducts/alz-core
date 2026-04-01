# Plan: Bulk Cost Price Update Endpoint

## Context

The `updateCostPrice` endpoint (`PUT /products/{sku}/cost-price`) was implemented as a single-item operation, but the existing `updatePrices` endpoint is a bulk endpoint accepting 1-100 SKUs. The frontend expects cost prices to follow the same bulk pattern. Additionally, the single-item implementation makes 3 API calls per SKU (resolve SKU, get suppliers, update), which scales poorly. The Linnworks API natively supports batching — `GetStockItemIdsBySKU` accepts multiple SKUs, and `UpdateStockSupplierStat` accepts an `itemSuppliers` array — so we can reduce this to exactly 3 API calls total regardless of batch size.

**Decisions**:
- Replace single endpoint with bulk (no dual endpoints)
- Leverage Linnworks array params (no HTTP pool transport changes)
- No caching for `getSuppliers()` — called once per bulk request
- Pre-validate SKU→supplier links via `ProductSupplierFactory` + domain validator (fail-fast entire batch)
- Create Application contract (`ProductSupplierLookupInterface`) for the factory

---

## 1. Pre-flight Validation: SKU→Supplier Link Check

### 1a. Application Contract for Supplier Lookup

**Create**: `app/Application/Contracts/Catalog/ProductSupplierLookupInterface.php`

```php
interface ProductSupplierLookupInterface
{
    /** @return list<ProductSupplier> */
    public function getByProductSku(string $sku): array;
}
```

Mirrors `ProductSupplierFactory::getByProductSku()`. The factory already implements the right shape — just add `implements ProductSupplierLookupInterface` and bind in the service provider.

**Modify**: `app/Infrastructure/Catalog/Product/Factories/ProductSupplierFactory.php` — add `implements ProductSupplierLookupInterface`

**Modify**: Service provider binding (likely `ShopwiredServiceProvider`) — bind interface to existing `scoped()` factory registration.

### 1b. Domain Validator

**Create**: `app/Domain/Catalog/Product/Validators/SkuSupplierLinkValidator.php`

Follows the `SkuBelongsToProductValidator` pattern exactly:
- Constructor receives `list<UpdateCostPriceCommand> $commands` + `array<string, list<ProductSupplier>> $suppliersBySku`
- `validate()` checks each command's SKU has the specified `supplierName` in its supplier list
- Returns `SkuSupplierLinkResult`

**Create**: `app/Domain/Catalog/Product/Validators/SkuSupplierLinkResult.php`

Follows `SkuBelongsToProductResult` pattern:
- `use ThrowsOnValidationFailureTrait`
- Carries `list<Sku> $unlinkedSkus` — SKUs that don't have the specified supplier linked
- `reason()`: "Supplier link validation failed: N SKU(s) do not have supplier 'X' linked"
- `context()`: `['unlinked_skus' => [...], 'supplier_name' => '...']`
- `orFail()` throws `ValidationFailedException` (via trait)

### 1c. Integration in Use Case

The use case calls the validator as a **fail-fast pre-flight step** before any API calls:

```
1. Assert::notEmpty($commands)
2. Build suppliersBySku map from ProductSupplierLookupInterface for each command's SKU
3. new SkuSupplierLinkValidator($commands, $suppliersBySku)->validate()->orFail()
4. (proceed to Linnworks API calls...)
```

If any SKU doesn't have the supplier linked, `ValidationFailedException` propagates to the global exception handler → **422 Unprocessable Entity** with the unlinked SKU details.

---

## 2. Result Objects (Application Layer)

Create in `app/Application/Catalog/Results/`:

**`CostPriceUpdateResult.php`** — `total`, `succeeded`, `failures` (list of `FailedCostPriceUpdate`). Add `allSucceeded()` and `hasFailures()` helpers.

**`FailedCostPriceUpdate.php`** — per-item failure: `Sku $sku` + `string $error`.

> No skipped/temporary types. `UpdateStockSupplierStat` is all-or-nothing; individual failures only happen during SKU resolution (SKU not found in Linnworks).

---

## 3. Application Contracts

**Modify** `app/Application/Contracts/Linnworks/InventoryClientInterface.php`:
- Add `resolveStockItemIds(list<Sku>): array<string, Guid>` — bulk SKU→stockItemId resolution. Missing SKUs omitted from result.

**Modify** `app/Application/Contracts/Linnworks/InventoryUpdateClientInterface.php`:
- Add `updateSupplierPurchasePrices(list<UpdateCostPriceCommand>): CostPriceUpdateResult` — bulk update. Keep existing single-item method.

---

## 4. Infrastructure: `InventoryClient`

**File**: `app/Infrastructure/Linnworks/Clients/InventoryClient.php`

Implement `resolveStockItemIds(array $skus)`:
1. Extract SKU strings: `array_map(fn(Sku $s) => $s->value, $skus)`
2. Call existing private `getStockItemIdsBySkus(list<string>)` (already supports multiple SKUs)
3. Build `array<string, Guid>` map from `SkuStockIdMappingResponse` results

No changes to the private method.

---

## 5. Infrastructure: `InventoryUpdateClient`

**File**: `app/Infrastructure/Linnworks/Clients/InventoryUpdateClient.php`

Implement `updateSupplierPurchasePrices(array $commands)`:

```
1. Extract unique SKUs from commands
2. $skuToGuid = $this->inventoryClient->resolveStockItemIds($skus)  // 1 API call
3. Identify unresolved SKUs → FailedCostPriceUpdate("SKU not found in Linnworks")
4. $suppliers = $this->inventoryClient->getSuppliers()               // 1 API call
5. $supplierGuid = findSupplierGuidByName($suppliers, $supplierName) // throws ResourceNotFoundException
6. Build itemSuppliers array for all resolved SKUs
7. postFormParams('/api/Inventory/UpdateStockSupplierStat', ...)     // 1 API call
8. Return CostPriceUpdateResult
```

**Error strategy**: Supplier-not-found throws (affects all items). SKU-not-found is per-item failure. Transport errors propagate.

---

## 6. Use Case Refactoring

**File**: `app/Application/Catalog/UseCases/UpdateCostPriceUseCase.php`

New dependencies: `ProductSupplierLookupInterface` (injected via constructor).

Change signature: `execute(list<UpdateCostPriceCommand> $commands): CostPriceUpdateResult`

```
1. Assert::notEmpty($commands)
2. Pre-flight: build suppliersBySku map, run SkuSupplierLinkValidator->validate()->orFail()
3. $apiResult = $this->inventoryUpdateClient->updateSupplierPurchasePrices($commands)
4. Best-effort local DB updates for succeeded items (loop, try/catch per item)
5. Log summary
6. Return $apiResult
```

Local DB: Loop over commands whose SKUs aren't in failures, call existing `StockItemRepository::updateSupplierPurchasePrice()` per item. Individual failures logged as warnings. ≤100 items → individual UPDATEs fine.

---

## 7. Presentation DTOs

**Delete**: `app/Presentation/Http/Api/DTOs/UpdateCostPriceRequestDTO.php`

**Create**: `app/Presentation/Http/Api/DTOs/UpdateCostPricesRequestDTO.php` (wrapper)
- `string $supplierName` ��� shared, required, min:1
- `DataCollection<CostPriceItemDTO> $items` — `#[Min(1), Max(100)]`

**Create**: `app/Presentation/Http/Api/DTOs/CostPriceItemDTO.php` (per-item)
- `string $sku` — required, min:1
- `float $costPrice` — required, numeric, min:0
- `toCommand(string $supplierName): UpdateCostPriceCommand`

---

## 8. Controller + Route

**Route** (`routes/api.php:149`): `PUT /products/cost-prices` → `updateCostPrices`

**Controller** (`ProductUpdateController.php`): Replace `updateCostPrice()` with `updateCostPrices(UpdateCostPricesRequestDTO $data): JsonResponse`
- Iterate items → `toCommand($data->supplierName)` → `list<UpdateCostPriceCommand>`
- Call use case, return 200 JSON: `{ total, succeeded, failures: [{ sku, error }] }`

---

## File Summary

| Action | File |
|--------|------|
| **Create** | `app/Application/Contracts/Catalog/ProductSupplierLookupInterface.php` |
| **Create** | `app/Domain/Catalog/Product/Validators/SkuSupplierLinkValidator.php` |
| **Create** | `app/Domain/Catalog/Product/Validators/SkuSupplierLinkResult.php` |
| **Create** | `app/Application/Catalog/Results/CostPriceUpdateResult.php` |
| **Create** | `app/Application/Catalog/Results/FailedCostPriceUpdate.php` |
| **Create** | `app/Presentation/Http/Api/DTOs/UpdateCostPricesRequestDTO.php` |
| **Create** | `app/Presentation/Http/Api/DTOs/CostPriceItemDTO.php` |
| **Modify** | `app/Infrastructure/Catalog/Product/Factories/ProductSupplierFactory.php` — add `implements` |
| **Modify** | Service provider — bind `ProductSupplierLookupInterface` to existing factory |
| **Modify** | `app/Application/Contracts/Linnworks/InventoryClientInterface.php` — add `resolveStockItemIds()` |
| **Modify** | `app/Application/Contracts/Linnworks/InventoryUpdateClientInterface.php` �� add bulk method |
| **Modify** | `app/Infrastructure/Linnworks/Clients/InventoryClient.php` — implement bulk resolution |
| **Modify** | `app/Infrastructure/Linnworks/Clients/InventoryUpdateClient.php` �� implement bulk update |
| **Modify** | `app/Application/Catalog/UseCases/UpdateCostPriceUseCase.php` — bulk + validation |
| **Modify** | `app/Presentation/Http/Api/Controllers/ProductUpdateController.php` — refactor method |
| **Modify** | `routes/api.php` ��� change route |
| **Delete** | `app/Presentation/Http/Api/DTOs/UpdateCostPriceRequestDTO.php` |

## Implementation Order

1. Application contract: `ProductSupplierLookupInterface` + factory `implements` + binding
2. Domain validator: `SkuSupplierLinkValidator` + `SkuSupplierLinkResult`
3. Application result objects: `CostPriceUpdateResult` + `FailedCostPriceUpdate`
4. Application contracts: `resolveStockItemIds()` + `updateSupplierPurchasePrices()`
5. Infrastructure: `InventoryClient` + `InventoryUpdateClient` implementations
6. Use case refactoring (depends on all above)
7. Presentation: DTOs → controller → route
8. Delete old DTO

Steps 1-4 have no mutual dependencies and can be done in parallel. Step 5 depends on 3-4. Step 6 depends on 1-5. Step 7 depends on 6.

## Verification

1. `make lint` — PHPStan, PHPArkitect, Deptrac, Pint all pass
2. `make test` — existing tests pass (no regressions)
3. Manual API test: `PUT /api/products/cost-prices` with bulk JSON body
4. Verify 3 Linnworks API calls via logs regardless of item count
5. Verify local DB updated for succeeded items
6. Verify fail-fast: send a SKU with wrong supplier → 422 with unlinked SKU details
7. Verify partial SKU resolution failure: include an unknown-in-Linnworks SKU → appears in `failures`
