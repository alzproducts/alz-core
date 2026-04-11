# Plan: Daily Product Sort-Order Sync from Popularity Snapshot

## Context

Issue #529 (just shipped) produces a weekly `catalog.product_popularity_snapshots` table and a `catalog.product_popularity_ranking_latest` view. Each snapshot row carries a `calculated_sort_order` (the desired rank based on the popularity algorithm) alongside `current_sort_order` (the value of `shopwired.products.sort_order` captured at snapshot time).

We now need to **push those calculated sort orders back to ShopWired** so the store reflects the popularity ranking. Strategy:

1. A **daily orchestrator job** queries the latest snapshot, joins it against the **live** `shopwired.products` table, and finds products where `calculated_sort_order` differs from the current live `sort_order`.
2. For each difference, it dispatches a **single per-product job** that PUTs the new `sort_order` to ShopWired via the existing `ProductFieldUpdateClientInterface`.

This mirrors the exact orchestrator-plus-per-product-dispatch pattern used by `SyncVatReliefFiltersJob` → `SyncVatReliefFiltersUseCase` → `UpdateProductFilterJob`.

**Why "live" and not the snapshot's `current_sort_order`?** The snapshot is weekly; the live value can drift between runs (manual edits, other syncs). Comparing against the live value is the authoritative answer to "does this product need correcting right now?". The user's requirement wording explicitly says "against the live product database sort orders".

**Why daily (not weekly)?** The snapshot runs weekly, but the live store may drift mid-week (manual edits, other syncs reverting), so a daily reconcile keeps the store converged on the latest snapshot without waiting a full week.

## Architecture overview

```
┌─────────────────────────────────────┐       daily @ 04:00 Europe/London
│ SyncProductSortOrdersJob            │  ← orchestrator, ShouldBeUnique, low queue
│   → SyncProductSortOrdersUseCase    │
│       → ProductSortOrderQueryRepo   │  SELECT from catalog view
│           (JOIN latest snapshot     │  JOIN shopwired.products
│            against live products)   │
│       → foreach diff:               │
│           CatalogSyncDispatcher     │
│             ::dispatchSortOrderUpdate
└─────────────────────────────────────┘
                 │  dispatches one job per product
                 ▼
┌─────────────────────────────────────┐
│ UpdateProductSortOrderJob           │  ← per-product, bulk queue
│   handle(ProductFieldUpdateClient)  │
│     $client->update(                │
│       $productId->value,            │
│       ProductFieldUpdate::sortOrder($n)
│     )                               │
└─────────────────────────────────────┘
```

## Files to modify

### New files

1. **`app/Application/Catalog/DTOs/ProductSortOrderChangeDTO.php`** — `final readonly`, two fields: `IntId $productId`, `int $calculatedSortOrder`.

2. **`app/Application/Contracts/Catalog/ProductSortOrderQueryRepositoryInterface.php`**
   - Single method `getProductsWithSortOrderDifferences(): array` returning `list<ProductSortOrderChangeDTO>`.
   - `@throws DatabaseOperationFailedException`, `DuplicateRecordException`, `ExternalServiceUnavailableException`.

3. **`app/Infrastructure/Catalog/Repositories/ProductSortOrderQueryRepository.php`**
   - Mirror of `VatReliefFilterQueryRepository.php:26-73` exactly.
   - `final` class (not `final readonly` — injects `EloquentGateway` via `private readonly` promoted constructor, like the VAT repo).
   - `private const string MODEL_CLASS = ProductModel::class;` (namespace: `App\Infrastructure\Catalog\Product\Models\ProductModel`).
   - `#[Override]` attribute on the interface method.
   - Executes the raw query through the gateway using the exact VAT pattern:
     ```php
     /** @var list<object{product_id: int, calculated_sort_order: int}> $rows */
     $rows = $this->eloquentGateway->query(static fn(): array => self::MODEL_CLASS::query()
         ->getConnection()
         ->select(<<<'SQL'
             SELECT
                 l.parent_external_id     AS product_id,
                 l.calculated_sort_order
             FROM catalog.product_popularity_ranking_latest l
             INNER JOIN shopwired.products p
                 ON p.external_id = l.parent_external_id
             WHERE p.is_active = true
               AND l.calculated_sort_order IS DISTINCT FROM p.sort_order
             SQL
         ));
     ```
     Verified columns: `shopwired.products.external_id` (integer, unique) and `sort_order` (integer, nullable) both exist (`database/migrations/2026_01_17_050000_create_shopwired_products_table.php:25`, `database/migrations/2026_03_23_100000_add_sort_order_to_shopwired_products.php:19`). `is_active` is NOT NULL boolean with index (line 44,70 of the same file).
   - Maps rows via a static `mapRowsToDtos()` → `ProductSortOrderChangeDTO` using `IntId::from($row->product_id)` and plain `$row->calculated_sort_order` (already `int` from Postgres smallint).

4. **`app/Application/Catalog/UseCases/SyncProductSortOrdersUseCase.php`**
   - `final readonly`. Dependencies: `ProductSortOrderQueryRepositoryInterface`, `CatalogSyncDispatcherInterface`, `LoggerInterface`.
   - Mirrors `SyncVatReliefFiltersUseCase.php:36-65`: log "starting", call repo, return early if empty, foreach → `$this->dispatcher->dispatchSortOrderUpdate($change->productId, $change->calculatedSortOrder)`, log count.
   - No try/catch (Application-layer "don't catch" rule).

5. **`app/Infrastructure/Jobs/Catalog/SyncProductSortOrdersJob.php`**
   - Exact copy of `SyncVatReliefFiltersJob` shape: `ShouldBeUnique`, `ShouldQueue`, low queue, `$tries=4`, `$timeout=120`, `uniqueFor=3600` (1h — generous buffer around the expected ~minute runtime; matches VAT-relief's 20-min-for-hourly ratio), `middleware = [new HandleDatabaseExceptions()]`.
   - `uniqueId()` → `'sync-product-sort-orders'`.
   - `handle(SyncProductSortOrdersUseCase $useCase)` → `$useCase->execute()`.

6. **`app/Infrastructure/Jobs/Catalog/UpdateProductSortOrderJob.php`**
   - Exact copy of `UpdateProductFilterJob.php:58-88` shape.
   - Constructor: `public function __construct(public IntId $productId, public int $sortOrder)`. `$this->onQueue(QueueName::Bulk->value)`.
   - Props: `$tries=6`, `$maxExceptions=3`, `$timeout=60`, `$backoff=[60, 300, 900]`, `retryUntil() = now()->addHours(4)`.
   - Middleware: `ServiceRateLimiter::shopwiredApiBulk()`, `ServiceCircuitBreaker::shopwired()`, `new HandleApiExceptions()`.
   - `handle(ProductFieldUpdateClientInterface $client)` →
     ```php
     $client->update($this->productId->value, ProductFieldUpdate::sortOrder($this->sortOrder));
     ```
   - `@throws` list: `ResourceNotAvailableException`, `InvalidApiRequestException`, `AuthenticationExpiredException`, `ExternalServiceUnavailableException` (copied from `ProductFieldUpdateClientInterface::update()`).

### Modified files

7. **`app/Application/Contracts/Catalog/CatalogSyncDispatcherInterface.php`** — add one method:
   ```php
   public function dispatchSortOrderUpdate(IntId $productId, int $sortOrder): void;
   ```

8. **`app/Infrastructure/Catalog/Dispatchers/QueuedCatalogSyncDispatcher.php`** — implement the new method:
   ```php
   #[Override]
   public function dispatchSortOrderUpdate(IntId $productId, int $sortOrder): void
   {
       UpdateProductSortOrderJob::dispatch($productId, $sortOrder);
   }
   ```

9. **`app/Providers/CatalogServiceProvider.php`** — this is a `DeferrableProvider`, so TWO edits are required:
   - Add `ProductSortOrderQueryRepositoryInterface::class` to the `provides()` array (`CatalogServiceProvider.php:42-50`). If you skip this, the deferred provider never registers the binding and container resolution silently fails at job-handler injection time.
   - Register the scoped binding inside `registerRepositories()` (or a new `registerSortOrderRepositories()` private method to match the `registerProductAttributeFilterRepositories()` / `registerShippingFilterRepositories()` grouping pattern):
     ```php
     $this->app->scoped(
         ProductSortOrderQueryRepositoryInterface::class,
         ProductSortOrderQueryRepository::class,
     );
     ```
   - **Use `scoped`, not `bind`** — matches every other catalog repository binding in this file and is the correct lifetime for Octane (per-request scope, released between requests).

10. **`app/Providers/Schedule/CatalogScheduleServiceProvider.php`** — add `registerProductSortOrderSyncSchedule()` and call it from `boot()`:
    ```php
    Schedule::job(new SyncProductSortOrdersJob())
        ->name('sync-product-sort-orders')
        ->dailyAt('04:00')
        ->timezone('Europe/London')
        ->onOneServer()
        ->withoutOverlapping(30);
    ```
    Runs at 04:00 Europe/London — one hour after the weekly snapshot job (Sunday 03:00), so every daily run — including Sunday — consumes the freshest snapshot rather than racing ahead of it. Buffer covers typical snapshot runtime + overrun.

## Existing code to reuse (do not reinvent)

| Need | Use |
|------|-----|
| ShopWired PUT `sort_order` | `ProductFieldUpdateClientInterface::update()` at `app/Application/Contracts/Shopwired/ProductFieldUpdateClientInterface.php:27` |
| Build the update payload | `ProductFieldUpdate::sortOrder(int)` at `app/Domain/Catalog/Product/ValueObjects/ProductFieldUpdate.php:53` |
| Rate limit / breaker middleware | `ServiceRateLimiter::shopwiredApiBulk()`, `ServiceCircuitBreaker::shopwired()` (same as `UpdateProductFilterJob`) |
| DB exception translation | `HandleDatabaseExceptions` middleware on orchestrator; `EloquentGateway::query()` in repo (wraps `DatabaseGatewayInterface::query()` which translates `QueryException`/`PDOException` to the three domain exceptions) |
| API exception translation | `HandleApiExceptions` middleware on per-product job |
| Orchestrator→UseCase→Repo→Dispatcher pattern | Copy `SyncVatReliefFiltersJob`/`SyncVatReliefFiltersUseCase`/`VatReliefFilterQueryRepository`/`QueuedCatalogSyncDispatcher` 1:1 |
| Per-product bulk job shape | Copy `UpdateProductFilterJob` — only change constructor args + `handle()` body |

## Deviation notes (flag at review)

1. **No new DB view.** The VAT-relief pattern materialises a `catalog.products_with_changed_vat_relief_filters` view; we query `catalog.product_popularity_ranking_latest` inline. Rationale: the user explicitly said "We only need the final view that returns the latest snapshot" — i.e. do not create a second view. The inline `WHERE ... IS DISTINCT FROM` predicate is simple enough to live in the repository query.

2. **Comparison is against live `shopwired.products.sort_order`, not the snapshot's `current_sort_order` column.** The snapshot column is stale by up to 7 days; the live join is authoritative. This is the interpretation of "against the live product database sort orders" in the user's requirement.

3. **Per-product job name uses `Update*` prefix** (not `Sync*`). Allowed by `app/Infrastructure/Jobs/CLAUDE.md` prefixes (`Sync|Process|Reconcile|Set|Update|Cleanup`) and matches existing `UpdateProductFilterJob`.

4. **`WHERE p.is_active = true` is part of the selection predicate.** The raw requirement is "find differences and correct them", but pushing `sort_order` updates to inactive (parked/hidden) products is wasteful API traffic and violates admin intent — when a merchandiser deactivates a product, they don't want automation silently nudging its rank. The filter uses the indexed NOT NULL `shopwired.products.is_active` column so it costs nothing.

## Verification

### Local smoke test

1. Ensure the 529 snapshot table has data:
   ```sql
   SELECT snapshot_date, COUNT(*) FROM catalog.product_popularity_snapshots GROUP BY snapshot_date;
   SELECT COUNT(*) FROM catalog.product_popularity_ranking_latest;
   ```

2. Preview what the orchestrator will find (run the repo SQL manually):
   ```sql
   SELECT l.parent_external_id, l.calculated_sort_order, p.sort_order AS current_live
   FROM catalog.product_popularity_ranking_latest l
   INNER JOIN shopwired.products p ON p.external_id = l.parent_external_id
   WHERE p.is_active = true
     AND l.calculated_sort_order IS DISTINCT FROM p.sort_order
   LIMIT 20;
   ```
   Also run without `LIMIT` + `COUNT(*)` to get expected dispatch volume for step 3.

3. Dispatch the orchestrator locally (per `CLAUDE.md` — tinker, never Railway):
   ```bash
   php artisan tinker --execute="App\Infrastructure\Jobs\Catalog\SyncProductSortOrdersJob::dispatchSync();"
   ```
   Expect in `storage/logs/laravel.log`:
   - `SyncProductSortOrders: starting`
   - `SyncProductSortOrders: completed` with a `dispatched` count
   - One Horizon queue entry per diff on the `bulk` queue

4. Pick one dispatched `UpdateProductSortOrderJob` from Horizon and confirm the ShopWired `PUT /products/{id}` body contains `{"sortOrder": <n>}` (check Octane + ShopWired sandbox logs).

   **First-run volume note**: the snapshot covers ~2,500 products and the initial run may dispatch a large fraction of them (first-ever sync, all `current` values will differ from `calculated`). This is the same volume the VAT-relief sync handles routinely on the bulk queue with the same rate limiter + circuit breaker — watch Horizon bulk-queue depth but no special handling needed.

5. After the bulk queue drains (first run), re-run the SQL preview from step 2 — it should now return zero rows, proving the live store has converged on the latest snapshot. (Note: avoid re-dispatching the orchestrator inside the `uniqueFor=3600` window; the preview is a cheaper idempotency check.)

6. Lint + tests:
   ```bash
   make lint
   make test
   ```
   Watch for:
   - PHPArkitect job-prefix rule (`SyncProductSortOrdersJob`, `UpdateProductSortOrderJob` — both allowed prefixes)
   - PHPStan `@throws` enumeration on the new interface + job
   - Deptrac layer dependencies (Application → Infrastructure is interface-only)

### Production verification (after deploy)

1. Horizon shows `sync-product-sort-orders` in scheduled jobs.
2. First morning after deploy at ~04:05 Europe/London — tail Sentry for failures, check the bulk queue depth on Horizon.
3. Spot-check one product in ShopWired admin vs the `calculated_sort_order` column for that product in the latest snapshot.

## Out of scope (explicit)

- No changes to the 529 snapshot view or table.
- No new `catalog.*` views.
- No new ShopWired API client code (the client already supports `sortOrder`).
- No unit tests (same rationale as the 529 PR — integration-heavy orchestrator with a trivial happy-path).
