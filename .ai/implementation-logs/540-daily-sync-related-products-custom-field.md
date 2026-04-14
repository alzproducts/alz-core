# Implementation Log: #540 — Daily sync of related products custom field from algorithm

## Issue Context
A tested SQL algorithm computes related products for each active product using category Jaccard similarity, title trigram similarity, and popularity scores — with support for manual pins, exclusions, and self-exclusion. Productionising this as a daily sync that writes computed results to a ShopWired `related_products` custom field (type: `ProductList`).

Follows orchestrator → per-product job pattern from Best Sellers sync. Key difference: per-product ordered list (not set membership). Updates via `UpdateProductCustomFieldsUseCase` rather than category membership job.

## Implementation

### Phase 1: Migration
- Created `database/migrations/2026_04_14_100000_create_catalog_related_products_algorithm_params_table.php`
- Versioned config table with partial unique index on is_active=true
- CHECK constraints: positive weights, max_results 2–20
- Seeded v1 row from plan calibrated defaults
- Added `pg_trgm` extension statement

### Phase 2: Domain Layer
- Created `app/Domain/Catalog/RelatedProducts/ValueObjects/RelatedProductsAlgorithmParams.php`
- Readonly VO with Webmozart Assert validation
- Matches all config table columns

### Phase 3: Application Contracts
- Created `RelatedProductsAlgorithmParamsRepositoryInterface` — `getActiveParams(): RelatedProductsAlgorithmParams`
- Created `RelatedProductsQueryRepositoryInterface` — `computeRelatedProducts(...)` returns `array<int, list<int>>`
- Created `RelatedProductsStateQueryRepositoryInterface` — `getCurrentRelatedProducts()` returns `array<int, list<int>>`
- Modified `ShopwiredSyncDispatcherInterface` — added `dispatchRelatedProductsUpdate()`

### Phase 4: Infrastructure Repositories
- Created `RelatedProductsAlgorithmParamsRepository` — queries `catalog.related_products_algorithm_params WHERE is_active = true`
- Created `RelatedProductsQueryRepository` — production SQL with external IDs threaded through, debug params stripped, PARTITION BY product_id fix
- Created `RelatedProductsStateQueryRepository` — reads current `related_products` from `shopwired.products.custom_fields`

### Phase 5: Application Use Case
- Created `SyncRelatedProductsUseCase` — fetch params, run algorithm, diff per-product, dispatch updates

### Phase 6: Infrastructure Jobs
- Created `SyncRelatedProductsJob` — orchestrator, QueueName::Low, timeout=300, ShouldBeUnique
- Created `UpdateProductCustomFieldsJob` — generic per-product job, QueueName::Bulk, rate-limited

### Phase 7: Infrastructure Dispatcher
- Modified `QueuedShopwiredSyncDispatcher` — added `dispatchRelatedProductsUpdate()`

### Phase 8: DI & Config
- Modified `CatalogServiceProvider` — added bindings for all 3 new repository interfaces

### Phase 9: Schedule
- Modified `CatalogScheduleServiceProvider` — added `registerRelatedProductsSyncSchedule()` at 04:30

### Phase 10: Post-Sweep Refactoring (5 improvements)
1. **Column renames**: `w_cat`→`category_weight`, `w_title`→`title_weight`, `w_pop`→`popularity_weight` across migration, VO, SQL, and repository
2. **Auto-increment PK**: Added `$table->id()` to `related_products_algorithm_params`, changed `algorithm_version` from `primary()` to `unique()`
3. **Eloquent model for params**: Created `RelatedProductsAlgorithmParamsModel` with `EloquentDomainMappableInterface` (`toDomain()` + `fromDomainAttributes()`), replaced raw SQL in `RelatedProductsAlgorithmParamsRepository`
4. **IntId value objects**: All product ID arrays changed from `array<int, list<int>>` to `array<int, list<IntId>>` across interfaces, repositories, use case, and dispatcher. Array keys remain `int` (PHP constraint); values use `IntId`
5. **ProductViewQueryRepository**: Replaced `RelatedProductsStateQueryRepository` with new `ProductViewQueryRepository` backed by `ProductViewModel`, reads current related products from `custom_fields` JSONB

### Phase 10 Lint Fixes
- `SyncRelatedProductsUseCase`: extracted `idsMatch()` static method for IntId comparison (brought `dispatchChanges()` under 20-line limit)
- Proper PHPDoc on `idsMatch()` params (inline `@param` on single line not parsed by PHPStan)

## Test Results

`make test` — 2981 passed, 0 failures.

## Lint Results

`make lint` — all 5 linters pass (Pint, PHPStan level-max, PHPArkitect, Deptrac, TLint). 0 errors, 0 violations.

## Handoff Notes

- **pg_trgm required**: The `similarity()` SQL function requires the `pg_trgm` extension. Migration runs `CREATE EXTENSION IF NOT EXISTS pg_trgm SCHEMA extensions;` — verify the `extensions` schema is available on the target Supabase instance.
- **`RelatedProductsAlgorithmSql`** is a pure static fragment class — no DI, no state. Not a service; deliberately not in a `Services/` directory.
- **`UpdateProductCustomFieldsJob`** is generic by design — takes `array $customFields` for future reuse. Type safety enforced at the dispatcher interface level.
- **Order-sensitive diff**: The comparison is `===` (not set equality). Reordered lists trigger an update. This matches the use case since ShopWired renders related products in display order.
- **Self-excluded products** (those with `related_exclude_self` set) get their related list from reverse-pinners only — products that pin them. Normal algorithmic scoring is bypassed for self-excluded products to avoid circular recommendations.
