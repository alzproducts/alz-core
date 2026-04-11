# Implementation Log: #530 — Daily sync of product sort orders from popularity snapshot

## Issue Context
Issue #529 produces a weekly `catalog.product_popularity_ranking_latest` view with `calculated_sort_order` for each product. This issue implements the daily reconciliation job that pushes those sort orders back to ShopWired. Mirrors the SyncVatReliefFiltersJob → UseCase → Repository → Dispatcher → per-product job pattern exactly.

## Implementation

### Sub-task 1: DTO + Interface + Repository
- `app/Application/Catalog/DTOs/ProductSortOrderChangeDTO.php` — final readonly DTO with `IntId $productId` and `int $calculatedSortOrder`
- `app/Application/Contracts/Catalog/ProductSortOrderQueryRepositoryInterface.php` — single `getProductsWithSortOrderDifferences()` method returning `list<ProductSortOrderChangeDTO>`
- `app/Infrastructure/Catalog/Repositories/ProductSortOrderQueryRepository.php` — executes raw SQL joining `catalog.product_popularity_ranking_latest` against live `shopwired.products`, filtered to `is_active = true` and `IS DISTINCT FROM`

### Sub-task 2: Use Case
- `app/Application/Catalog/UseCases/SyncProductSortOrdersUseCase.php` — mirrors SyncVatReliefFiltersUseCase shape: log starting, call repo, early return if empty, foreach dispatch, log count

### Sub-task 3: Jobs
- `app/Infrastructure/Jobs/Catalog/SyncProductSortOrdersJob.php` — daily orchestrator: ShouldBeUnique, low queue, `$uniqueFor=3600`, HandleDatabaseExceptions middleware
- `app/Infrastructure/Jobs/Catalog/UpdateProductSortOrderJob.php` — per-product: bulk queue, rate limiter + circuit breaker + HandleApiExceptions, uses `ProductFieldUpdateClientInterface::update()`

### Sub-task 4: Interface extension + dispatcher
- `app/Application/Contracts/Catalog/CatalogSyncDispatcherInterface.php` — added `dispatchSortOrderUpdate(IntId, int): void`
- `app/Infrastructure/Catalog/Dispatchers/QueuedCatalogSyncDispatcher.php` — implemented `dispatchSortOrderUpdate()`, dispatches `UpdateProductSortOrderJob`

### Sub-task 5: Service provider + schedule
- `app/Providers/CatalogServiceProvider.php` — added `ProductSortOrderQueryRepositoryInterface::class` to `provides()` and scoped binding
- `app/Providers/Schedule/CatalogScheduleServiceProvider.php` — added `registerProductSortOrderSyncSchedule()` called from `boot()`, `dailyAt('04:00')` Europe/London

## Test Results
- `make test-quick`: 1464 tests passed, 2738 assertions — all green

## Lint Results
- `make fix`: Pint auto-fixed `ProductSortOrderQueryRepository.php` (heredoc argument spacing, trailing comma, braces position)
- `make lint`: All 5 linters pass — Pint ✅ PHPStan ✅ PHPArkitect ✅ Deptrac ✅ TLint ✅
- Zero violations, zero suppressions

## Handoff Notes
- No new tests needed (plan explicitly excluded them — same rationale as #529: integration-heavy orchestrator with trivial happy path)
- The `CatalogServiceProvider` is a `DeferrableProvider`: both the `provides()` array AND the scoped binding must be kept in sync or the container silently fails to resolve the interface
- First production run will be large (potentially thousands of dispatches); same bulk-queue + rate-limiter + circuit-breaker stack as VAT-relief, handles this volume routinely
- After deploy: verify Horizon shows `sync-product-sort-orders` in scheduled jobs; spot-check product sort orders against the snapshot after first 04:00 run
