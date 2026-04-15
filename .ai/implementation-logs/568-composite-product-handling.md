# Implementation Log: #568 Composite Product Handling in Catalog Views

## Branch
`feature/568-composite-product-handling-catalog-views`

## Decision Log

- **0a: Stop clobbering** — Made `StockItemFull::$isComposite` nullable (`?bool`). `StockItemFullResponse` maps `isCompositeParent` (null when absent from GetStockItemsFull). `StockItemModelMapper::toModelAttributes()` spreads conditionally to skip writing `is_composite` when null.
- **0b: Composite flags sync** — Simplified from plan: query returns only composite GUIDs (`list<Guid>`) instead of `{stockItemId, isComposite}` pairs. Repository reuses existing `applyFlagSync()` two-pass pattern. Hourly schedule.
- **Domain changes** — `ProductVariationView` gets `$isComposite`, `$canEditCostPrice` (computed), `$inventory`. `commonDefaultSupplier()` filters out composite variations. `ProductViewMeta` accepts `?bool $isComposite` with early-return in `resolveCanEditCostPrice()`. `ProductView` passes `$isComposite` through to `ProductViewMeta`.
- **Infrastructure** — Assembler resolves `isComposite` from always-loaded `stockItem` relation (not gated by include). Variation inventory only resolved when `ProductInclude::Inventory` requested.
- **Presentation** — `ProductVariationResource` serializes `is_composite`, `can_edit_cost_price`, conditional `inventory`. `ProductResource` serializes `is_composite`.

## Files Changed

### Phase 0a: Stop clobbering
- `app/Domain/Inventory/ValueObjects/StockItemFull.php` — `bool` → `?bool`
- `app/Infrastructure/Linnworks/Responses/StockItemFullResponse.php` — added `isCompositeParent`, updated `toDomain()`
- `app/Infrastructure/Linnworks/Mappers/StockItemModelMapper.php` — conditional spread in `toModelAttributes()`

### Phase 0b: Composite flags sync
- `app/Infrastructure/Linnworks/Queries/CompositeStockItemFlagsQuery.php` — NEW
- `app/Application/Linnworks/UseCases/SyncCompositeStockItemFlagsUseCase.php` — NEW
- `app/Infrastructure/Jobs/Linnworks/SyncCompositeStockItemFlagsJob.php` — NEW
- `app/Application/Contracts/Linnworks/StockDashboardsClientInterface.php` — added `getCompositeStockItemIds()`
- `app/Infrastructure/Linnworks/Clients/StockDashboardsClient.php` — implemented method
- `app/Application/Contracts/Linnworks/StockItemRepositoryInterface.php` — added `syncCompositeFlags()`
- `app/Infrastructure/Linnworks/Repositories/EloquentStockItemRepository.php` — implemented method
- `app/Providers/Schedule/LinnworksScheduleServiceProvider.php` — hourly composite flags schedule

### Steps 1-2: Domain
- `app/Domain/Catalog/Product/ValueObjects/ProductVariationView.php` — `$isComposite`, `$canEditCostPrice`, `$inventory`, updated `commonDefaultSupplier()`
- `app/Domain/Catalog/Product/ValueObjects/ProductViewMeta.php` — `$isComposite` param, early-return
- `app/Domain/Catalog/Product/ValueObjects/ProductView.php` — `$isComposite` param, passed to `ProductViewMeta`

### Steps 3-4: Infrastructure
- `app/Infrastructure/Catalog/Product/Mappers/ProductVariationModelMapper.php` — new params
- `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php` — 3 new resolvers, wiring

### Step 5: Presentation
- `app/Presentation/Http/Api/Resources/ProductVariationResource.php` — serialized new fields
- `app/Presentation/Http/Api/Resources/ProductResource.php` — serialized `is_composite`

### Step 6: Tests
- `tests/Unit/Domain/Catalog/Product/ValueObjects/ProductVariationViewTest.php` — `canEditCostPrice` + `commonDefaultSupplier` composite tests
- `tests/Unit/Domain/Catalog/Product/ValueObjects/ProductViewMetaTest.php` — composite-aware `canEditCostPrice` tests

## Simplify Review

- **Fixed**: `CompositeStockItemFlagRow` — removed dead `$bContainsComposites` field (SQL WHERE already filters), adopted `#[MapInputName]` convention per canonical `StockItemBySkuQuery` template
- **Dismissed**: variation-level `toProductInventory()` always called — VO construction is trivial (8 attribute wraps, zero I/O), gating would require 5th param violating 4-param limit
- **Dismissed**: `canEditCostPrice` at two levels — intentional distinct semantics (per-variation vs product meta)
- **Dismissed**: `relationLoaded` guard duplication — pre-existing pattern, not this PR's scope

## PR Notes

TBD — will be drafted after all steps complete.
