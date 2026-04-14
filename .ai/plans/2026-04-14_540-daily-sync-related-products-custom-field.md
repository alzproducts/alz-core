# Sync Related Products Custom Field

## Context

We have a tested SQL algorithm (`tmp/related-products-test.sql`) that computes related products for each active product using category Jaccard similarity, title trigram similarity, and popularity scores — with support for manual pins, exclusions, and self-exclusion. We need to productionise this as a daily sync that writes the computed results to a ShopWired `related_products` custom field (type: `ProductList`).

This follows the exact same orchestrator → per-product job pattern as the existing Best Sellers category sync, but targets a custom field instead of category membership, and sources from a complex algorithm query instead of a simple DB view.

**Key difference from Best Sellers**: Best Sellers is a set membership problem (in/out of category). Related Products is a per-product ordered list problem (each product gets its own ranked list). The diff logic is per-product array comparison, and updates use `ProductUpdateClient::updateCustomFields()` (fetch-merge-PUT) instead of `ProductFieldUpdateClient::update()`.

**Prerequisites**: The `related_products` custom field already exists in ShopWired but isn't synced locally yet — run a full custom field sync locally before testing. `pg_trgm` extension needed for `similarity()` function.

---

## Implementation Plan

### Phase 1: Database Migration

**New file: `database/migrations/YYYY_create_catalog_related_products_algorithm_params_table.php`**

- Follow the versioned config pattern from `2026_04_12_100000_create_catalog_product_popularity_ranking_config_table.php`
- Table: `catalog.related_products_algorithm_params`
- Columns:
  - `algorithm_version` SMALLINT (PK)
  - `w_cat` DECIMAL(5,3) — category similarity weight
  - `w_title` DECIMAL(5,3) — title trigram weight
  - `w_pop` DECIMAL(5,3) — popularity weight
  - `max_results` SMALLINT — max related products per product
  - `min_content_score` DECIMAL(5,3) — minimum combined content score threshold
  - `default_popularity` DECIMAL(5,3) — fallback for unranked products
  - `exclude_compare_list` BOOLEAN — whether to exclude compare_list products
  - `is_active` BOOLEAN (partial unique index WHERE is_active = true)
  - `notes` TEXT nullable
  - `created_at` TIMESTAMPTZ
- CHECK constraints: positive weights, max_results BETWEEN 2 AND 20
- Seed v1 row: w_cat=0.45, w_title=0.35, w_pop=0.20, max_results=8, min_content_score=0.10, default_popularity=1.0, exclude_compare_list=true
- Enable `pg_trgm` extension: `CREATE EXTENSION IF NOT EXISTS pg_trgm` (required for `similarity()` function)

### Phase 2: Domain Layer

**New file: `app/Domain/Catalog/RelatedProducts/ValueObjects/RelatedProductsAlgorithmParams.php`**

- Readonly VO with typed properties matching the config columns
- Constructor validation via `Webmozart\Assert` (positive weights, max_results range)
- No framework dependencies

### Phase 3: Application Contracts

**New file: `app/Application/Contracts/Catalog/RelatedProductsAlgorithmParamsRepositoryInterface.php`**
- `getActiveParams(): RelatedProductsAlgorithmParams`
- `@throws ResourceNotFoundException` if no active row

**New file: `app/Application/Contracts/Catalog/RelatedProductsQueryRepositoryInterface.php`**
- `computeRelatedProducts(RelatedProductsAlgorithmParams $params): array`
- Returns `array<int, list<int>>` — productExternalId → ordered relatedExternalIds
- `@throws DatabaseOperationFailedException`, `DuplicateRecordException`, `ExternalServiceUnavailableException`

**Modify: `app/Application/Contracts/Shopwired/ShopwiredSyncDispatcherInterface.php`**
- Add: `dispatchRelatedProductsUpdate(IntId $productId, list<int> $relatedProductIds): void`

### Phase 4: Infrastructure — Repositories

**New file: `app/Infrastructure/Catalog/Repositories/RelatedProductsAlgorithmParamsRepository.php`**
- Queries `catalog.related_products_algorithm_params WHERE is_active = true`
- Uses `EloquentGateway::query()` with raw SQL
- Maps row → `RelatedProductsAlgorithmParams` VO

**New file: `app/Infrastructure/Catalog/Repositories/RelatedProductsQueryRepository.php`**
- Contains the production SQL as heredoc (debug scaffolding stripped)
- SQL modifications from test version:
  - Remove `debug_num_products`, `debug_row_limit`, `debug_title_filter` params and final debug WHERE/LIMIT
  - Thread `product_id` (internal) and `product_external_id` through all 3 UNION ALL branches in `combined` CTE (test version only carries `product_title` for display). Similarly thread `related_external_id` alongside existing `related_product_id`
  - Change self-excluded `ROW_NUMBER() OVER (PARTITION BY product_title ...)` to `PARTITION BY product_id` (title-based partitioning risks merging products with duplicate titles)
  - Final SELECT returns `product_external_id, related_external_id, position` — the only columns the orchestrator needs
- Binds params from `RelatedProductsAlgorithmParams` VO as query parameters
- Post-processes rows into `array<int, list<int>>` grouped by product_external_id, ordered by position

### Phase 5: Application — Use Cases

**New file: `app/Application/Catalog/UseCases/SyncRelatedProductsUseCase.php`**
- Dependencies:
  - `RelatedProductsAlgorithmParamsRepositoryInterface`
  - `RelatedProductsQueryRepositoryInterface`
  - `RelatedProductsStateQueryRepositoryInterface` (for reading current custom field state)
  - `ShopwiredSyncDispatcherInterface`
  - `LoggerInterface`
- Pipeline:
  1. Log start
  2. Fetch active algorithm params from DB
  3. Run algorithm query → `array<int, list<int>>` (desired state)
  4. Read current `related_products` custom field values via `RelatedProductsStateQueryRepositoryInterface` (current state)
  5. Diff: union all product IDs from both maps. For each product, compare desired vs current (order-sensitive `===`). If different, dispatch update. This naturally handles adds (new entries), changes (list reordered/changed), and clears (product had related products but now computes to empty).
  6. Dispatch `dispatchRelatedProductsUpdate()` for each changed product
  7. Log summary (total products, dispatched count, unchanged count)

**Reuse existing: `app/Application/Catalog/UseCases/UpdateProductCustomFieldsUseCase.php`**
- The per-product update reuses the existing use case rather than creating a dedicated one
- Validation overhead is negligible — it queries the local custom field registry (DB read, cached), not the API
- Most daily syncs will only dispatch a handful of changed products
- Called with: `$useCase->execute($productId, ['related_products' => $relatedProductIds])`

### Phase 6: Infrastructure — Jobs

**New file: `app/Infrastructure/Jobs/Catalog/SyncRelatedProductsJob.php`**
- Mirrors `SyncBestSellersCategoryJob` structure
- `ShouldBeUnique`, `ShouldQueue`, `QueueName::Low`
- `$timeout = 300` (heavier SQL than Best Sellers' 120s)
- `uniqueId = 'sync-related-products'`, `uniqueFor = 3600`
- Middleware: `HandleDatabaseExceptions`
- `handle(SyncRelatedProductsUseCase $useCase)` → delegates

**New file: `app/Infrastructure/Jobs/Shopwired/UpdateProductCustomFieldsJob.php`**
- **Generic reusable job** for any custom field update dispatched from the sync layer
- `QueueName::Bulk`, `$tries = 6`, `$timeout = 60`
- Middleware: `ServiceRateLimiter::shopwiredApiBulk()`, `ServiceCircuitBreaker::shopwired()`, `HandleApiExceptions()`
- Constructor: `IntId $productId`, `array<string, string|int|bool|list<string>|list<int>|null> $customFields`
- `handle(UpdateProductCustomFieldsUseCase $useCase)` → calls `$useCase->execute($this->productId, $this->customFields)`
- Type safety is enforced at the dispatcher interface level — the job itself accepts raw fields for reusability

### Phase 7: Infrastructure — Dispatcher

**Modify: `app/Infrastructure/Shopwired/Dispatchers/QueuedShopwiredSyncDispatcher.php`**
- Add `dispatchRelatedProductsUpdate(IntId $productId, list<int> $relatedProductIds)`:
  - Converts typed params to raw array: `['related_products' => $relatedProductIds]`
  - Dispatches generic `UpdateProductCustomFieldsJob::dispatch($productId, $rawFields)`
  - Type safety lives here; the job is reusable for future custom field syncs

### Phase 8: DI & Config

**Modify: `app/Providers/CatalogServiceProvider.php`**
- Add `registerRelatedProductsRepositories()` binding all three new repository interfaces (Params, Query, State)
- Add interface class strings to `provides()` array

### Phase 9: Schedule

**Modify: `app/Providers/Schedule/CatalogScheduleServiceProvider.php`**
- Add `registerRelatedProductsSyncSchedule()`
- Schedule: `dailyAt('04:30')` Europe/London — 30 min after Best Sellers (04:00)
- `->onOneServer()->withoutOverlapping(60)`

### Phase 10: Reading Current State (diff support)

**New file: `app/Application/Contracts/Catalog/RelatedProductsStateQueryRepositoryInterface.php`**
- `getCurrentRelatedProducts(): array<int, list<int>>`
- Returns productExternalId → current related product external IDs from local DB
- `@throws DatabaseOperationFailedException`, `DuplicateRecordException`, `ExternalServiceUnavailableException`

**New file: `app/Infrastructure/Catalog/Repositories/RelatedProductsStateQueryRepository.php`**
- Implements `RelatedProductsStateQueryRepositoryInterface`
- Single SQL query: reads `external_id` and `custom_fields->'related_products'` from `shopwired.products` for all active products
- Parses the JSONB array into `array<int, list<int>>`
- Uses `EloquentGateway::query()` for exception wrapping

---

## Key Files to Reference (existing patterns)

| Purpose | File |
|---------|------|
| Orchestrator job pattern | `app/Infrastructure/Jobs/Catalog/SyncBestSellersCategoryJob.php` |
| Orchestrator use case pattern | `app/Application/Catalog/UseCases/SyncBestSellersCategoryUseCase.php` |
| Raw SQL repository pattern | `app/Infrastructure/Catalog/Repositories/BestSellersRankingStateQueryRepository.php` |
| Versioned config migration | `database/migrations/2026_04_12_100000_create_catalog_product_popularity_ranking_config_table.php` |
| Custom field update client | `app/Infrastructure/Shopwired/Clients/ProductUpdateClient.php` |
| Dispatcher interface | `app/Application/Contracts/Shopwired/ShopwiredSyncDispatcherInterface.php` |
| Per-product job pattern | `app/Infrastructure/Jobs/Shopwired/UpdateProductCategoryMembershipJob.php` (structure reference) |
| Service provider bindings | `app/Providers/CatalogServiceProvider.php` |
| Schedule registration | `app/Providers/Schedule/CatalogScheduleServiceProvider.php` |

---

## Verification

1. **Migration**: `php artisan migrate` — verify table created with v1 seed row
2. **SQL correctness**: Run algorithm query via tinker against dev database, compare output with test SQL results
3. **Dispatch locally**: `php artisan tinker --execute="SyncRelatedProductsJob::dispatch();"` — check `storage/logs/laravel.log` for orchestrator + per-product job logs
4. **Linting**: `make lint` — all new files pass PHPStan, PHPArkitect, Deptrac
5. **Tests**: Unit tests for use cases (mock repos/dispatcher), VO validation tests
