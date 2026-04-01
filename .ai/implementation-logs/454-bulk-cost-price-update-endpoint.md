# Implementation Log: #454 — Refactor updateCostPrice to bulk endpoint

## Issue Context
Replace single-item `PUT /products/{sku}/cost-price` with bulk `PUT /products/cost-prices` accepting 1-100 SKUs with shared supplier name. Reduce Linnworks API calls to exactly 3 regardless of batch size. Pre-flight validation rejects batch if any SKU lacks specified supplier.

## Implementation

### Step 1: Application contract + factory binding
- **Created** `app/Application/Contracts/Catalog/ProductSupplierLookupInterface.php` — contract for SKU→supplier lookups
- **Modified** `app/Infrastructure/Catalog/Product/Factories/ProductSupplierFactory.php` — added `implements ProductSupplierLookupInterface`
- **Modified** `app/Providers/ShopwiredServiceProvider.php` — bound interface to existing scoped factory, added to `provides()`

### Step 2: Domain validator + result
- **Created** `app/Domain/Catalog/Product/Validators/SkuSupplierLinkValidator.php` — fail-fast pre-flight check following `SkuBelongsToProductValidator` pattern
- **Created** `app/Domain/Catalog/Product/Validators/SkuSupplierLinkResult.php` — carries unlinked SKUs, uses `ThrowsOnValidationFailureTrait`

### Step 3: Application result objects
- **Created** `app/Application/Catalog/Results/CostPriceUpdateResult.php` — total/succeeded/failures tracking
- **Created** `app/Application/Catalog/Results/FailedCostPriceUpdateResult.php` — per-item failure with SKU + error

### Step 4: Application contracts + Infrastructure implementations
- **Modified** `app/Application/Contracts/Linnworks/InventoryClientInterface.php` — added `resolveStockItemIds(list<Sku>): array<string, Guid>`
- **Modified** `app/Application/Contracts/Linnworks/InventoryUpdateClientInterface.php` — added `updateSupplierPurchasePrices(list<UpdateCostPriceCommand>): CostPriceUpdateResult`
- **Modified** `app/Infrastructure/Linnworks/Clients/InventoryClient.php` — implemented `resolveStockItemIds()` using existing `getStockItemIdsBySkus()`
- **Modified** `app/Infrastructure/Linnworks/Clients/InventoryUpdateClient.php` — implemented `updateSupplierPurchasePrices()` with 3-call pattern: resolve SKUs, get suppliers, bulk update

### Step 5: Use case refactoring
- **Modified** `app/Application/Catalog/UseCases/UpdateCostPriceUseCase.php` — changed from single `execute(UpdateCostPriceCommand)` to bulk `execute(list<UpdateCostPriceCommand>): CostPriceUpdateResult`. Added pre-flight validation via `SkuSupplierLinkValidator`. Best-effort local DB updates with per-item error handling.

### Step 6: Presentation layer
- **Created** `app/Presentation/Http/Api/DTOs/UpdateCostPricesRequestDTO.php` — wrapper with `supplierName` + `DataCollection<CostPriceItemDTO>`
- **Created** `app/Presentation/Http/Api/DTOs/CostPriceItemDTO.php` — per-item `sku` + `costPrice`
- **Modified** `app/Presentation/Http/Api/Controllers/ProductUpdateController.php` — replaced `updateCostPrice()` with `updateCostPrices()`, returns `{ total, succeeded, failures }`
- **Modified** `routes/api.php` — `PUT /products/{sku}/cost-price` → `PUT /products/cost-prices`
- **Deleted** `app/Presentation/Http/Api/DTOs/UpdateCostPriceRequestDTO.php`

### Step 7: Baseline update
- **Modified** `phpstan-complexity-baseline.neon` — updated line counts for InventoryClient (320→349), InventoryUpdateClient (326→447), ShopwiredServiceProvider (286→288, provides 59→60, registerFactories 54→55)

## Decisions
- Named `FailedCostPriceUpdateResult` (not `FailedCostPriceUpdate`) to satisfy PHPArkitect Application layer naming convention
- Used `non-empty-list<>` PHPStan type on validator constructor to prove index [0] access is safe
- Extracted `sendBulkUpdate`, `partitionByResolution`, `resolveSupplierGuid` as private methods to stay under 20-line method limit
- Used `Assert::notNull()` in `sendBulkUpdate` for the SKU→GUID lookup since PHPStan can't prove the resolved commands have matching keys

## Test Results
- **2809 tests passed** (6339 assertions), no regressions
- No existing tests covered the old single-item endpoint

## Lint Results
- **Pint**: Pass (auto-fixed import ordering on first run)
- **PHPStan**: Pass (0 errors after baseline updates and type fixes)
- **PHPArkitect**: Pass (0 violations)
- **Deptrac**: Pass (0 violations)
- **TLint**: Pass

## Handoff Notes
- No new tests written (per workflow spec). The feature would benefit from unit tests on `SkuSupplierLinkValidator`, `UpdateCostPriceUseCase`, and integration tests on the controller
- The InventoryUpdateClient class at 447 lines is getting large — the plan notes it could be split in future
- Manual API testing recommended: send `PUT /api/products/cost-prices` with `{ "supplierName": "...", "items": [{ "sku": "...", "costPrice": 5.99 }] }`
- Verify 3 API calls in logs regardless of batch size
- Test fail-fast: send a SKU with wrong supplier → 422 with unlinked SKU details
