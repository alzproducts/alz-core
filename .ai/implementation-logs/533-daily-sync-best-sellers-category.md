# Implementation Log: #533 — Automatic daily sync of ShopWired Best Sellers category from popularity ranking

## Issue Context

Issue #529 produces a weekly `catalog.product_popularity_ranking_latest` view with a `final_score`
for each active product. Without a write-back step the ShopWired storefront's "Best Sellers" category
(ID 64943) stays in whatever manual order it was last set to. This implements a daily reconciliation
job that automatically keeps the top-48 ranked products in category 64943 and removes products that
have fallen out of the ranking.

## Implementation

### Files Changed

**Config**
- `config/shopwired.php` — added `best_sellers_limit` key (default 48 from env `SHOPWIRED_BEST_SELLERS_LIMIT`) and `best_sellers_category_id` key (default 64943 from env `SHOPWIRED_BEST_SELLERS_CATEGORY_ID`) — runtime source of truth for the category ID

**Database migration**
- `database/migrations/2026_04_12_100004_create_catalog_products_best_sellers_ranking_state_view.php`
  — creates `catalog.products_best_sellers_ranking_state` view joining `product_popularity_ranking_latest`
  with `shopwired.products`. Baked literals: `64943` (category ID containment predicate) and `2.00`
  (minimum final_score to exclude non-sellers pinned at 1.00)

**Application layer**
- `app/Application/Catalog/DTOs/BestSellersRankingStateDTO.php` — readonly DTO: `IntId $productId`, `bool $currentHasBestSellers`
- `app/Application/Contracts/Catalog/BestSellersRankingStateQueryRepositoryInterface.php` — `findAll(): list<BestSellersRankingStateDTO>`
- `app/Application/Catalog/UseCases/SyncBestSellersCategoryUseCase.php` — orchestrator with:
  - `public const int BEST_SELLERS_CATEGORY_ID = 64943`
  - Pre-flight guard: throws `ResourceNotFoundException` if category missing or inactive
  - PHP diff: slices top `$bestSellersLimit` from ordered rankings, dispatches only flipping products
  - Extracted `buildTopIdSet()` and kept `dispatchFlips()` under 20-line limit
- `app/Application/Contracts/Catalog/CatalogSyncDispatcherInterface.php` — added `dispatchBestSellersMembershipUpdate(IntId, bool): void`

**Infrastructure layer**
- `app/Infrastructure/Catalog/Repositories/BestSellersRankingStateQueryRepository.php` — simple SELECT from the view via `EloquentGateway::query()`
- `app/Infrastructure/Catalog/Dispatchers/QueuedCatalogSyncDispatcher.php` — implemented `dispatchBestSellersMembershipUpdate()` dispatching `UpdateProductBestSellersMembershipJob`
- `app/Infrastructure/Jobs/Catalog/UpdateProductBestSellersMembershipJob.php` — per-product job:
  - Fetches live product, applies idempotency guard, mutates `category_ids`
  - Extracted `buildNewCategoryIds()` static method to keep `handle()` under 20 lines
  - `QueueName::Bulk`, `tries=6`, backoff [60, 300, 900]s, `retryUntil=4h`
  - Middleware: `shopwiredApiBulk` rate limiter + circuit breaker + `HandleApiExceptions`
- `app/Infrastructure/Jobs/Catalog/SyncBestSellersCategoryJob.php` — orchestrator job:
  - `ShouldBeUnique`, `QueueName::Low`, `tries=3`, `uniqueFor=3600`, `HandleDatabaseExceptions`

**Provider wiring**
- `app/Providers/CatalogServiceProvider.php` — bound `BestSellersRankingStateQueryRepositoryInterface` in both `registerRepositories()` and `provides()` (required for `DeferrableProvider`)
- `app/Providers/Schedule/CatalogScheduleServiceProvider.php` — registered `registerBestSellersCategorySchedule()` at `dailyAt('04:00')` Europe/London, `withoutOverlapping(30)`
- `app/Providers/ShopwiredServiceProvider.php` — added `registerBestSellersBindings()` injecting `$bestSellersLimit` from `shopwired.best_sellers_limit` config

### Key Decisions

- **`final_score >= 2.00` in view**: non-sellers are pinned to exactly `1.00` in the ranking; this filter excludes dead stock from ever qualifying
- **PHP diff, not SQL**: use case loads ~2,500 `(int, bool)` rows (~40KB) and computes the diff in a single pass — simpler than SQL with a runtime `:limit` parameter
- **Per-product job references `SyncBestSellersCategoryUseCase::BEST_SELLERS_CATEGORY_ID`**: avoids a second PHP constant for the same value (Infrastructure → Application cross-layer reference is allowed by Clean Architecture rules)
- **Category 64943 baked into view SQL**: follows the filter-sync precedent of baking stable category IDs as DDL literals

## Post-Sweep Refactor (Generalization)

During `/sweep` the user requested generalizing the per-product job/use case so any ShopWired product can have categories added and/or removed in a single idempotent PUT. Changes:

**New**
- `app/Application/Shopwired/CategoryMembership/UseCases/UpdateProductCategoryMembershipUseCase.php`
  — owns full check-merge-update workflow: fetch product → `array_diff`/`array_intersect` to compute effective adds/removes → no-op log + return if both empty → else merge (`buildNewCategoryIds()` static helper) + PUT + success log. Extracted private `applyUpdate()` to satisfy 20-line method limit.
- `app/Infrastructure/Jobs/Catalog/UpdateProductCategoryMembershipJob.php`
  — thin generic job: `(IntId, list<int> adds, list<int> removes)` → delegates to the new use case. Same queue/middleware stack as the deleted specialized job.

**Deleted**
- `app/Infrastructure/Jobs/Catalog/UpdateProductBestSellersMembershipJob.php` — obsoleted by generic job.

**Modified**
- `CatalogSyncDispatcherInterface::dispatchBestSellersMembershipUpdate(IntId, bool)` → `dispatchCategoryMembershipUpdate(IntId, list<int>, list<int>)`
- `QueuedCatalogSyncDispatcher` — dispatches the new generic job.
- `SyncBestSellersCategoryUseCase`:
  - Removed `BEST_SELLERS_CATEGORY_ID` constant (now injected via contextual binding from `config('shopwired.best_sellers_category_id')`)
  - Added `$bestSellersCategoryId` constructor parameter alongside existing `$bestSellersLimit`
  - New `dispatchFlip()` private helper builds the adds/removes tuple per direction
  - Extracted `dispatchAndReport()` from `execute()` for line-length
- `CatalogServiceProvider` — owns both contextual bindings (`$bestSellersLimit` + `$bestSellersCategoryId`) via new `registerBestSellersBindings()` + `resolveNumericConfig()` helper (fail-fast on missing/invalid config per Providers CLAUDE.md).
- `ShopwiredServiceProvider` — removed the best-sellers contextual binding (moved to CatalogServiceProvider).
- Migration docblock — updated comment on the `64943` literal to note `config('shopwired.best_sellers_category_id')` is the runtime source of truth.

### Key Decisions

- **Idempotency lives entirely inside the use case**, not in the job or a Skip middleware. Skip middleware was rejected because it would (a) double-fetch the product, (b) silently drop the job violating the "log all code paths" project rule, and (c) couple the job to `ProductRepositoryInterface` via service-locator `app()`.
- **Unified add+remove over split Add/Remove jobs** — one PUT can atomically move a product between categories; `array_diff`/`array_intersect` is strictly more powerful than a simple membership boolean and handles any future caller that wants to toggle multiple categories in one shot.
- **Generic job/use case lives under `Shopwired/CategoryMembership/`** — mirrors the existing `Shopwired/SaleManagement/` precedent. This is a ShopWired product mutation, not a Best-Sellers concept.
- **`AddProductToSaleUseCase` intentionally NOT refactored** — it batches categories + sort_order + custom_fields into a single variadic `ProductFieldUpdate` PUT; splitting it would cost an extra API call per product.

### Sweep Fixes (pre-refactor, still in place)

- `BestSellersRankingStateQueryRepository` — extracted private static `mapRowsToDtos()`.
- `SyncBestSellersCategoryUseCase` — replaced O(N×M) `in_array` lookup with O(1) `isset` map (`buildTopIdLookup()`).

## Post-Sweep Review Round 2

User review of the generalized design flagged four issues; all fixed in one pass.

1. **Dispatcher lives in wrong namespace.** `dispatchCategoryMembershipUpdate` was on `CatalogSyncDispatcherInterface` but a category mutation is a ShopWired product change — the data source doesn't matter. Moved to `ShopwiredSyncDispatcherInterface` + `QueuedShopwiredSyncDispatcher`. The job file also moved: `Infrastructure/Jobs/Catalog/UpdateProductCategoryMembershipJob.php` → `Infrastructure/Jobs/Shopwired/UpdateProductCategoryMembershipJob.php` (namespace updated).
2. **DB view was dead weight.** `catalog.products_best_sellers_ranking_state` baked the `64943` literal (now a runtime config) and still required PHP-side sorting/filtering. Migration deleted, view dropped from local DB, migration record cleared from `migrations` table. All SQL now lives inside the repository.
3. **Tuple dispatch was hard to read.** The `[$adds, $removes] = $shouldBeMember ? [[cat], []] : [[], [cat]];` branch is gone — `dispatchAdds()` and `dispatchRemoves()` each build their own explicit parameter lists.
4. **Loop was iterating non-members (the biggest bucket).** Previous design pulled ~all active sellers from the view then walked every row to skip no-ops. Replaced with two focused queries and a set diff:
   - `findTopRankedProductIds(int $limit): list<int>` — `LIMIT`-capped read from `catalog.product_popularity_ranking_latest` with `final_score >= 2.00`.
   - `findProductIdsInCategory(int $categoryId): list<int>` — `shopwired.products` filtered via the GIN-indexed `category_ids @> ?::jsonb` predicate.
   - Use case: `toAdd = array_diff(top, current)`, `toRemove = array_diff(current, top)`. Only products needing an API call even appear in the PHP arrays.

### Collateral changes
- Deleted `BestSellersRankingStateDTO` (no longer used; repo returns plain `list<int>`).
- Introduced `BestSellersRankingStateQueryRepository::MIN_SELLER_SCORE = 2.00` class constant (previously a magic number inside the view SQL).
- `execute()` now logs three distinct paths: `starting`, `no ranking snapshot yet — skipping`, and `dispatched membership updates` — preserves the "log all code paths" project rule while avoiding the old view-based no-op row log.

## Test Results

2981 passed (6888 assertions) — no regressions

## Lint Results

- Fixed `alz.excessiveMethodLength` in `dispatchFlips()` (27 lines → extracted `buildTopIdSet()`)
- Fixed `alz.excessiveMethodLength` in `handle()` (22 lines → extracted `buildNewCategoryIds()`)
- Fixed `missingType.checkedException` — added `DuplicateRecordException` to interface, repository, use case, and orchestrator job
- Fixed `cast.useless` — removed redundant `(int)` and `(bool)` casts in repository (types annotated in PHPDoc)

Final: `make lint` passes with 0 errors, 0 suppressions

## Handoff Notes

- No smoke test run (requires live DB + queue worker) — can be triggered locally with: `php artisan tinker --execute="App\\Infrastructure\\Jobs\\Catalog\\SyncBestSellersCategoryJob::dispatch();"`
- No new unit/integration tests written (out of scope for /work-fast) — the plan's verification plan documents what to write
- The `$bestSellersLimit` default of 48 and category ID 64943 are both stable; no `.env` changes needed unless overriding in a non-prod environment
