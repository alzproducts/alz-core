# Implementation Log: #560 — Allow product variants to be added to sales

**GitHub Issue**: #560
**Plan Document**: .ai/plans/2026-04-15_560-allow-variants-to-be-added-to-sales.md
**Status**: In Progress
**Started**: 2026-04-15

## Issue Context
The front-end needs to add individual product variants to sales. The price update write path already supports variant SKUs, but downstream sale state management (category membership, custom fields, reconciliation, expiry checks) assumes only the master product can be on sale. Two pre-existing bugs also need fixing: ProductView reads SaleSettings from wrong source, and reconciliation drift query causes an infinite loop.

## Implementation

### Phase 0: Fix pre-existing issues
- **ProductViewAssembler**: Changed SaleSettings source from DB (`product_sale_settings` table) to ShopWired custom fields (source of truth). Removed `SaleSettingsRepositoryInterface` dependency. Used new `SaleSettings::fromRawCustomFields()` factory.
- **EloquentProductRepository drift query**: Removed ALL custom field checks from `buildSaleStateDriftQuery()`. Simplified to price ↔ category alignment only. Extracted `whereAnySaleActive()`, `whereNoSaleActive()`, `variationSaleExistsSubquery()` helpers.
- **ProductSaleStateResolver**: Simplified `evaluate()` to only check price vs category membership. Removed `hasAnySaleCustomField()` check and `SkuSaleStateResult`.

### Phase 1: Core variant detection
- **Product VO**: Added `hasAnySaleActive()` (uses `array_any()`) and `allOnSaleSkus()`. Both handle master + variations, with variations inheriting parent price when null.
- **Product VO**: Added static `isSaleActive(?float $salePrice, float $price)` as single source of truth.
- **EloquentProductRepository**: `getProductsOnSale()` and drift queries now check variation sale prices via EXISTS subquery against `shopwired.product_variations`.
- **UpdateShopwiredAddToSaleJob**: Skip middleware changed from `->isOnSale()` to `->hasAnySaleActive()`.

### Phase 2: Remove legacy Linnworks sale state
- Deleted `UpdateLinnworksSaleStateJob` and `SkuSaleStateResult`
- Removed `dispatchUpdateSaleState()` from `SaleReconciliationDispatcherInterface` and its implementation
- Removed per-SKU dispatch loop from `ReconcileProductSaleStateUseCase`
- Removed `$skuSaleStates` from `ProductSaleStateResult`

### Phase 3: Expired sales variant cleanup
- **CheckExpiredSalesUseCase**: Changed from single-SKU removal to multi-SKU batch. Uses `$product->allOnSaleSkus()` to find all on-sale SKUs and removes them in one API call.

### Phase 4: persistSaleState guardrails
- **SaleStatePersistenceService** (extracted from UseCase): Handles sale settings persistence.
  - Addition guardrail: Skip upsert if settings already exist
  - Removal guardrail: Only delete settings when ALL on-sale SKUs are being removed (uses `allOnSaleSkusBeingRemoved()` with `array_all()`)

### Lint cleanup (decomposition)
- Extracted `SaleStatePersistenceService` from `UpdateProductSellingPricesUseCase` (307→~250 lines)
- Added `SaleSettings::fromRawCustomFields()` factory — shared by ProductViewAssembler and ReconcileProductSaleStateUseCase
- Compacted `Product::hasAnySaleActive()` using `array_any()`
- Updated complexity baselines for shifted line counts

## Decision Log

### 2026-04-15
- **Decision**: Read SaleSettings from custom fields, not DB table
- **Why**: Custom fields are authoritative — Google Shopping, storefront, and expiry checks all read them. DB table is write-path staging only.

- **Decision**: Remove all custom field checks from drift query
- **Why**: Custom fields update asynchronously after API write. Checking them in drift query caused infinite reconciliation loop (~5 products/hour) because local JSONB lagged behind API writes.

- **Decision**: Extract `SaleStatePersistenceService` as separate class
- **Why**: UpdateProductSellingPricesUseCase exceeded 250-line class limit after adding guardrails. Sale state persistence is a cohesive responsibility with its own dependencies.

- **Decision**: Add `SaleSettings::fromRawCustomFields()` as domain factory
- **Why**: Identical extraction logic duplicated in ProductViewAssembler (Infrastructure) and ReconcileProductSaleStateUseCase (Application). Moving to the VO is architecturally clean — only uses Domain types (SaleCustomField enum, DateTimeImmutable).

## Test Results
- 2992 tests passed (6885 assertions)
- Updated tests: ProductSaleStateResolverTest, CheckExpiredSalesUseCaseTest, ReconcileProductSaleStateUseCaseTest, UpdateProductSellingPricesUseCaseTest

## Lint Results
- All 5 linters pass: Pint, PHPStan, PHPArkitect, Deptrac, TLint
- Baseline updates: EloquentProductRepository (914→897), CheckExpiredSalesUseCase execute (56→57), ReconcileProductSaleStateUseCase execute (45→40)
- Removed baselines: buildSaleSettingsFromProduct (method removed), buildSaleStateDriftQuery (method decomposed)

## Handoff Notes
- All changes are uncommitted on `feature/560-allow-variants-added-to-sales`
- No new tests written — only existing tests updated to match new behavior
- The `UpdateLinnworksSaleStateJob` deletion removes dead code (`is_in_sale` EP is write-only)
- Variation price inheritance: variations with `null` price inherit parent's `$this->price` for sale comparison
