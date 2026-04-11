# Implementation Log: #523 — Sync ShopWired Shipping Options filter from stock availability

## Issue Context
ShopWired "Shipping Options" filter group (external_id 11412, option_no 25) needs to reflect real-time stock availability.
- Products with `stock > 0` (parent or any variation) → `"Next Day Delivery Available"` in slot 25
- Zero/null stock → slot 25 cleared
- 10-minute cron offset (`5-59/10 * * * *`) to read freshly-mirrored stock data after `SyncFullStockToShopwiredJob`

## Implementation

### Files Created
- `app/Domain/Catalog/Product/Enums/ShippingOptionsFilterValue.php` — Domain enum, single case `NextDayDeliveryAvailable`
- `app/Application/Contracts/Catalog/ShippingOptionsFilterQueryRepositoryInterface.php` — Application contract
- `app/Infrastructure/Catalog/Repositories/ShippingOptionsFilterQueryRepository.php` — Repository querying the view
- `app/Application/Catalog/UseCases/SyncShippingOptionsFiltersUseCase.php` — Use case orchestrator
- `app/Infrastructure/Jobs/Catalog/SyncShippingOptionsFiltersJob.php` — Orchestrator job ($tries=3, retryUntil=9min, uniqueFor=600)
- `database/migrations/2026_04_11_220000_create_catalog_products_with_changed_shipping_options_filters_view.php` — SQL view with stock null-guard + EXISTS join on variations
- `tests/Unit/Application/Catalog/UseCases/SyncShippingOptionsFiltersUseCaseTest.php` — Unit tests (empty, dispatch, clear, mixed)
- `tests/Integration/Catalog/ShippingOptionsFilterGroupGuardTest.php` — Guard test for external_id=11412, option_no=25

### Files Edited
- `app/Infrastructure/Shopwired/Enums/FilterGroupOptionNo.php` — Added `ShippingOptions = 25`, updated docblock
- `app/Providers/CatalogServiceProvider.php` — Registered new interface→implementation binding
- `app/Providers/Schedule/CatalogScheduleServiceProvider.php` — Added `registerShippingOptionsFilterSchedule()` with 10-min cron offset

### Key Decisions
- `$tries = 3` (not 4 like hourly siblings) — with `$backoff = [30, 60]` and `$timeout = 120`, all attempts fit within 9-minute `retryUntil`
- `$uniqueFor = 600` (10-min TTL, not 1200) — tighter cadence requires shorter lock TTL
- `->cron('5-59/10 * * * *')` — offsets 5 min after `SyncFullStockToShopwiredJob` (HH:00) so we read freshly-mirrored stock

## Test Results

- `make test-quick`: 1464 domain tests passed
- `make test`: 2981 tests passed (6888 assertions) — all green

## Lint Results

- **Pint**: pass (no style changes needed)
- **PHPStan**: 1 error fixed — `registerFilterQueryRepositories()` exceeded 20-line limit; split into `registerProductAttributeFilterRepositories()` + `registerShippingFilterRepositories()`
- **PHPArkitect**: no violations
- **Deptrac**: no violations
- **TLint**: LGTM

## Handoff Notes
- Guard test (`ShippingOptionsFilterGroupGuardTest`) will fail until `shopwired.filter_groups` contains `external_id = 11412` — this is expected and is a confirmed pre-ship blocker per the plan
- Schedule is wired up and ready; do not enable in prod until the filter-group row is seeded
