# Plan: Automatic Best-Sellers Category Sync

## Context

Issue #529 introduced `catalog.product_popularity_ranking_latest` — a weekly-refreshed
view of products ordered by `final_score DESC`. We now need to surface those rankings
to real shoppers by automatically maintaining the "Best Sellers" category in ShopWired
(category ID **64943**).

Every run should:

1. Compute which products should live in category 64943 (top N by `final_score`).
2. Compute a **diff** against the current membership — only products whose membership
   needs to flip are touched.
3. Issue one `PUT /products/{id}` per flipping product via the existing
   `ProductFieldUpdateClient`, preserving all other categories on the product.

Config-driven knob:
- `shopwired.best_sellers_limit` (default `48`) — how many products get the category.

Hardcoded constants (two places — one SQL, one PHP, both referencing the same value):
- `64943` baked into the SQL view migration (matches the filter-sync precedent of
  baking literals into `CREATE VIEW` DDL).
- `public const int BEST_SELLERS_CATEGORY_ID = 64943;` on
  `SyncBestSellersCategoryUseCase`. The per-product job references it as
  `SyncBestSellersCategoryUseCase::BEST_SELLERS_CATEGORY_ID` rather than declaring
  its own (avoids a second PHP hardcode). Infrastructure → Application cross-layer
  reference is allowed by Clean Architecture dependency rules.

Outcome: the storefront's "Best Sellers" landing page stays automatically in sync with
the popularity ranking, with no manual merchandising and no wasted API calls on
products whose membership is already correct.

---

## Architecture Overview

Clean-Architecture shape, mirroring the two existing precedents in this repo:

- **Diff computation**: single SQL query in a query repository (not a DB view — see
  "Design decision: query vs view" below).
- **Orchestrator use case + job**: fans diff rows out to per-product jobs, matching the
  `SyncVatReliefFiltersUseCase` / `SyncVatReliefFiltersJob` pair.
- **Per-product job**: fetches the live product, mutates `category_ids`, PUTs — matching
  the existing `AddProductToSaleUseCase` / `RemoveProductFromSaleUseCase` pair
  (closest behavioural twin since they mutate `category_ids` exactly the same way).

---

## Design decisions

### 1. Database view with baked literals (matches filter-sync precedent)

Every existing catalog filter-sync view bakes its literals directly into the
`CREATE VIEW` DDL — the category ID `64943` follows the same pattern. The `48`
limit is *not* baked: it's a selection boundary (not a lookup key) and should be
tunable without a DB migration, so it stays in config and is applied in PHP.

**Why not a query-time SQL repo?** The convention in this repo is "diff lives in
a view, repo is a trivial SELECT." Deviating from that for a single value would be
inconsistent. `64943` is a stable primary-key identifier for a storefront
category that isn't going to rotate — it deserves the same treatment as the
filter slot numbers (`'25'`) in the existing views.

**Why not a config table + `CROSS JOIN`?** That's the #529 ranking-algorithm
pattern, which is justified there because the algorithm has *eight* tunable
parameters. Best-sellers has one integer. A migration + table + seed + admin flow
for a single int would be over-engineered.

### 2. Split of concerns — SQL returns state, PHP computes the diff

`shopwired.products.category_ids` is a JSONB array shared with every other category
membership on the product. The mutation path must NOT overwrite the whole array —
it strips/re-appends only `64943`, preserving siblings. That mutation happens in PHP
inside the per-product job (copied verbatim from the sale-management precedent).

**The view's job**: emit one row per *eligible seller* with (a) ordering by
`final_score DESC` and (b) a boolean flagging whether the product currently has
category 64943 in its `category_ids` array. No LIMIT, no diff.

```sql
CREATE VIEW catalog.products_best_sellers_ranking_state AS
SELECT
    l.parent_external_id AS product_id,
    p.category_ids @> '[64943]'::jsonb AS current_has_best_sellers
FROM catalog.product_popularity_ranking_latest l
INNER JOIN shopwired.products p ON p.external_id = l.parent_external_id
WHERE l.is_active = true AND l.final_score >= 2.00
ORDER BY l.final_score DESC;
```

Baked literals: `64943`, `2.00`. The GIN index on `shopwired.products.category_ids`
(`2026_03_31_120000_add_category_ids_gin_index_shopwired_products.php`) makes the
containment predicate index-eligible.

**The use case's job** — compute the diff:

```text
$allRankings = $repo->findRankingState();       // list<RankingStateDTO>, ordered
$targetIds   = array_slice($allRankings, 0, $bestSellersLimit) → set of product IDs
foreach $allRankings as $row:
    $shouldBeMember = in_array($row->productId, $targetIds, strict)
    if $shouldBeMember !== $row->currentHasBestSellers:
        $dispatcher->dispatchBestSellersMembershipUpdate($row->productId, $shouldBeMember)
```

**Why `final_score >= 2.00`**: the ranking view SQL pins non-sellers to exactly `1.00`
(all four rank components hard-coded to `1` in the `ELSE 1::numeric` branches) and
sellers to `[2.00, max_rank]`. So `>= 2.00` cleanly separates sellers from
non-sellers. Without this filter, if the catalog ever has fewer than
`best_sellers_limit` genuine sellers, we'd promote dead stock.

**Why the diff is computed in PHP, not SQL**: the user's preference, and it's
legitimately simpler. The view returns ~2,500 rows of `(int, bool)` per run (~40KB),
which is cheap, and the PHP-side loop is a single pass with clear semantics. Doing
the diff in SQL would require a subquery for "is product in top N" bound to a
runtime `:limit` parameter, which is more SQL machinery for zero functional benefit.

### 3. Per-product write path — reuse the Sale-management shape

`ProductFieldUpdateClient::update()` (PUT `/products/{id}` with
`{"categories":[...]}`) replaces the full category list. The canonical
"add-one" / "remove-one" sequence is already implemented in
`AddProductToSaleUseCase` / `RemoveProductFromSaleUseCase`. The best-sellers job will:

1. Resolve the fresh product via `ProductRepositoryInterface::getProduct($id)` (this
   hydrates `$product->categoryIds`).
2. Re-check `$product->isInCategory($bestSellersCategoryId)` — idempotent guard in case
   the product was touched between SQL diff and job execution.
3. Build `ProductFieldUpdate::categories([...])` using either
   `[...$product->categoryIds, $cat]` (add) or
   `array_values(array_filter($product->categoryIds, fn($id) => $id !== $cat))` (remove).
4. Call `ProductFieldUpdateClient::update($productId, $update)`.

This is a line-by-line copy of the sale-management logic with a different category ID.

### 4. Scheduling — Daily 04:00 Europe/London

Rankings only *change* weekly (the underlying snapshot refreshes Sundays at 03:00),
but running daily provides drift protection: if a merchandiser manually edits the
Best Sellers category via the ShopWired admin UI mid-week, the next 04:00 run will
reconcile it. On the six days a week when no rankings have changed, the diff query
returns zero rows and the job is effectively a no-op costing one cheap SQL query.

**Why 04:00 and not 03:15**: the weekly snapshot job at Sunday 03:00 holds a 60-minute
`withoutOverlapping(60)` lock (see `CatalogScheduleServiceProvider::registerProductPopularityRankingSnapshotSchedule`).
If the expensive ranking view takes >15 minutes on Sundays, a 03:15 best-sellers sync
would read *last week's* snapshot and the storefront would lag by 24h on the most
important day for ranking freshness. 04:00 sits comfortably past the snapshot's
grace window. Registered in the existing `CatalogScheduleServiceProvider` next to
the other catalog syncs.

---

## Files to Create

### Config (edit, not create)

- **`config/shopwired.php`** — add alongside the existing `sale_category_id` block:
  ```php
  'best_sellers_limit' => (int) env('SHOPWIRED_BEST_SELLERS_LIMIT', 48),
  ```
  Only the limit is config-driven. The category ID `64943` is baked into the SQL
  view migration and the per-product job class constant.

- **`app/Providers/ShopwiredServiceProvider.php`** — extend the existing contextual
  binding block to inject `$bestSellersLimit` into the new orchestrator use case via
  `self::resolveNumericConfig('shopwired.best_sellers_limit')`, mirroring the
  `registerSaleManagementBindings()` method.

### Database migration (new)

- **`database/migrations/2026_04_12_100004_create_catalog_products_best_sellers_ranking_state_view.php`**
  — creates `catalog.products_best_sellers_ranking_state`. Baked literals: `64943`,
  `2.00`. See the SQL in Design Decision §2 above. Down migration drops the view.
  Migration filename includes the `catalog` schema per the database CLAUDE.md naming
  rule (`DROP SCHEMA CASCADE` resets rely on schema-name pattern matching).

### Application layer

- **`app/Application/Contracts/Catalog/BestSellersRankingStateQueryRepositoryInterface.php`**
  ```php
  /**
   * Returns every active seller from the latest popularity snapshot,
   * ordered by final_score DESC, each tagged with whether the product
   * currently has category 64943 in its ShopWired category_ids.
   *
   * @return list<BestSellersRankingStateDTO>
   * @throws DatabaseOperationFailedException
   * @throws ExternalServiceUnavailableException
   */
  public function findAll(): array;
  ```
  `@throws` enumerates every exception `DatabaseGateway::query()` can raise
  (per Application CLAUDE.md "Interface @throws Declarations" rule — PHPStan cannot
  verify this, so under-declaration silently propagates gaps).

- **`app/Application/Catalog/DTOs/BestSellersRankingStateDTO.php`** — readonly
  `IntId $productId`, `bool $currentHasBestSellers`. `IntId` per Domain CLAUDE.md.

- **`app/Application/Catalog/UseCases/SyncBestSellersCategoryUseCase.php`** — thin
  orchestrator. Class constant: `public const int BEST_SELLERS_CATEGORY_ID = 64943;`
  Injected deps: `BestSellersRankingStateQueryRepositoryInterface`,
  `CategoryRepositoryInterface` (existing — for the pre-flight guard),
  `CatalogSyncDispatcherInterface`, `LoggerInterface`, `int $bestSellersLimit`
  (contextual binding). Logic:
    1. **Pre-flight guard**: `$category = $this->categoryRepo->findByExternalId(self::BEST_SELLERS_CATEGORY_ID);`
       If `$category === null` (or `$category->active === false`), throw
       `ResourceNotFoundException('shopwired', 'Category', self::BEST_SELLERS_CATEGORY_ID)`.
       This is a permanent failure — the job will fail loudly rather than silently
       mis-categorise products.
    2. `$allRankings = $this->repo->findAll();` — ~2,500 DTOs ordered by score.
    3. Early-return on empty with a log line ("no ranking snapshot yet").
    4. Build the target set: `$topIds = array_map(→productId->value, array_slice($allRankings, 0, $this->bestSellersLimit));`
    5. Walk all rankings; for each row where `in_array($row->productId->value, $topIds, true) !== $row->currentHasBestSellers`, dispatch.
    6. Log add/remove counts. No try/catch (Application layer doesn't catch by default).

- **`app/Application/Contracts/Catalog/CatalogSyncDispatcherInterface.php`** — add
  `dispatchBestSellersMembershipUpdate(IntId $productId, bool $shouldBeMember): void`.
  Matches the `IntId` convention of the existing `dispatchFilterUpdate` method. No
  category ID param — the job owns `64943` as a class constant.

### Infrastructure layer

- **`app/Infrastructure/Catalog/Repositories/BestSellersRankingStateQueryRepository.php`**
  — implements the interface. One-line SELECT against the view using
  `DatabaseGateway::query()` — ordering is already baked into the view. Maps each
  row to `new BestSellersRankingStateDTO(IntId::from((int) $row->product_id), (bool) $row->current_has_best_sellers)`.

- **`app/Infrastructure/Catalog/Dispatchers/QueuedCatalogSyncDispatcher.php`** —
  implement the new `dispatchBestSellersMembershipUpdate` method as a one-liner:
  `UpdateProductBestSellersMembershipJob::dispatch($productId, $shouldBeMember);`
  Matches the shape of the existing `dispatchFilterUpdate` implementation.

- **`app/Infrastructure/Jobs/Catalog/UpdateProductBestSellersMembershipJob.php`**
  — per-product job. Copy `UpdateProductFilterJob` verbatim for the shape (tries,
  backoff, timeout, retryUntil, `QueueName::Bulk`, middleware stack:
  `ServiceRateLimiter::shopwiredApiBulk()` + `ServiceCircuitBreaker::shopwired()` +
  `HandleApiExceptions`). References the category ID via
  `SyncBestSellersCategoryUseCase::BEST_SELLERS_CATEGORY_ID` — no local constant,
  no constructor param for it. Constructor: `IntId $productId, bool $shouldBeMember`.
  `handle()` method-injects `ProductRepositoryInterface` +
  `ProductFieldUpdateClientInterface`, then:
    1. `$catId = SyncBestSellersCategoryUseCase::BEST_SELLERS_CATEGORY_ID;`
    2. `$product = $productRepo->getProduct($this->productId);` — fresh live state.
    3. Idempotency guard — `if ($this->shouldBeMember === $product->isInCategory($catId)) return;` (the view may be stale by seconds; saves a no-op PUT).
    4. Build the new category list:
       - Add: `[...$product->categoryIds, $catId]`
       - Remove: `array_values(array_filter($product->categoryIds, fn($id) => $id !== $catId))`
    5. `$fieldUpdateClient->update($this->productId->value, ProductFieldUpdate::categories($newIds));`
  Full `@throws` list copied from `ProductRepositoryInterface::getProduct()` and
  `ProductFieldUpdateClientInterface::update()` — mirrors `AddProductToSaleUseCase::execute()` lines 45–54.

- **`app/Infrastructure/Jobs/Catalog/SyncBestSellersCategoryJob.php`** — orchestrator.
  Copy `SyncVatReliefFiltersJob` shape (`ShouldBeUnique`, low queue, `tries=3`,
  `timeout=120`, `uniqueFor=3600`, `HandleDatabaseExceptions` middleware). `handle()`
  method-injects `SyncBestSellersCategoryUseCase` and calls `execute()`.

### Schedule

- **`app/Providers/Schedule/CatalogScheduleServiceProvider.php`** — add a new private
  `registerBestSellersCategorySchedule()` method following the existing style in this
  file (each schedule gets its own docblocked private method called from `boot()`):
  ```php
  Schedule::job(new SyncBestSellersCategoryJob())
      ->name('sync-best-sellers-category')
      ->dailyAt('04:00')
      ->timezone('Europe/London')
      ->onOneServer()
      ->withoutOverlapping(30);
  ```
  Order of chain matches precedent: `name()` first, then cadence, timezone,
  `onOneServer()`, `withoutOverlapping(30)`. The 30-minute lock mirrors the hourly
  filter-sync jobs — the orchestrator only enqueues per-product jobs, so it should
  finish in seconds. Runs every day; on the six days between weekly snapshot
  refreshes the diff query returns zero rows and the orchestrator exits cleanly.

### Service-provider wiring

- **`app/Providers/CatalogServiceProvider.php`** — bind
  `BestSellersRankingStateQueryRepositoryInterface` → concrete class inside
  `registerRepositories()` (or a new sibling private method). **Critical**: add the
  interface FQCN to both `provides()` AND the actual `scoped(...)` binding — this
  class implements `DeferrableProvider`, so a binding missing from `provides()`
  will silently fail to fire at resolution time.

---

## Critical files to re-read before implementation

- `app/Application/Shopwired/SaleManagement/UseCases/AddProductToSaleUseCase.php` —
  exact template for the per-product mutation logic.
- `app/Application/Shopwired/SaleManagement/UseCases/RemoveProductFromSaleUseCase.php`
  — removal counterpart.
- `app/Infrastructure/Jobs/Catalog/UpdateProductFilterJob.php` — per-product job shape
  (tries, backoff, middleware, retryUntil).
- `app/Application/Catalog/UseCases/SyncVatReliefFiltersUseCase.php` + sibling job —
  orchestrator shape.
- `app/Infrastructure/Catalog/Dispatchers/QueuedCatalogSyncDispatcher.php` — where to
  add the new dispatcher method.
- `app/Domain/Catalog/Product/ValueObjects/ProductFieldUpdate.php` — `::categories()`
  factory (already exists, no change needed).
- `database/migrations/2026_04_12_100003_create_catalog_product_popularity_ranking_latest_view.php`
  — column names for the SQL diff query.
- `app/Providers/ShopwiredServiceProvider.php` lines 301–341 — contextual-binding
  pattern (`registerSaleManagementBindings` + `resolveNumericConfig`) to copy for
  `$bestSellersLimit` injection into `SyncBestSellersCategoryUseCase`.

---

## Reused existing building blocks (no new code needed)

- `ProductFieldUpdateClientInterface::update()` — already handles category writes.
- `ProductFieldUpdate::categories(list<int>)` — already exists.
- `Product::isInCategory(int)` — already exists on the domain VO.
- `ProductRepositoryInterface::getProduct(IntId)` — already hydrates `categoryIds`.
- `CategoryRepositoryInterface::findByExternalId(int)` — already exists at
  `app/Application/Contracts/Shopwired/CategoryRepositoryInterface.php:41`, returns
  `?Category`. Used for the pre-flight guard in the orchestrator use case.
- `ResourceNotFoundException` — already exists in
  `app/Domain/Exceptions/Api/`. Constructor signature
  `(string $serviceName, string $resourceType, int|string $resourceId)` matches the
  existing pattern in `AddProductToSaleUseCase::execute()` line 65.
- `CatalogSyncDispatcherInterface` + `QueuedCatalogSyncDispatcher` — extend, don't replace.
- `ServiceRateLimiter::shopwiredApiBulk()` + `ServiceCircuitBreaker::shopwired()` +
  `HandleApiExceptions` — the standard job middleware stack.
- `CatalogScheduleServiceProvider` — the catalog schedule registry.
- `CatalogServiceProvider` — the catalog DI binder.

---

## Verification plan

### 1. Unit / integration tests

- **View / repository test** (`tests/Integration/Catalog/…`): seed
  `shopwired.products` + `catalog.product_popularity_snapshots` fixtures, call
  `findAll()`, assert:
  - Only active sellers (`is_active = true AND final_score >= 2.00`) appear.
  - Non-sellers (`final_score = 1.00`) are excluded.
  - `currentHasBestSellers` correctly reflects `category_ids @> '[64943]'::jsonb`
    for seeded products with and without the category.
  - Ordering is by `final_score DESC` (top-ranked product first).

- **Use case unit test**: mock `BestSellersRankingStateQueryRepositoryInterface`,
  mock `CategoryRepositoryInterface` to return a stub `Category` with
  `external_id = 64943, active = true`, mock the dispatcher. Hand-roll a fixture
  list of 5+ `BestSellersRankingStateDTO`s, set `$bestSellersLimit = 2`. Assert:
  - Products 1–2 currently out → dispatched with `$shouldBeMember = true`.
  - Products 3+ currently in → dispatched with `$shouldBeMember = false`.
  - Products already in correct state (top 2 currently in, bottom 3 currently out)
    → no dispatches.
  - Empty repo result → zero dispatches, log line emitted.

- **Best-sellers category existence guard — integration test**
  (`tests/Integration/Catalog/SyncBestSellersCategoryGuardTest.php`): a real-DB
  test that exercises both branches of the pre-flight guard on
  `SyncBestSellersCategoryUseCase::execute()`:
  - **Happy path**: seed `shopwired.categories` with a row
    `(external_id = 64943, active = true, title = 'Best Sellers', ...)` and any
    supporting rows needed to run the full pipeline; call
    `$useCase->execute()`; assert zero exceptions and the dispatcher was called
    (use a spy/fake dispatcher so no real jobs fire).
  - **Missing category**: truncate `shopwired.categories` (or simply seed
    without the 64943 row); call `$useCase->execute()`; assert it throws
    `ResourceNotFoundException` with `serviceName = 'shopwired'`,
    `resourceType = 'Category'`, `resourceId = 64943`.
  - **Inactive category**: seed the 64943 row with `active = false`; assert it
    throws the same `ResourceNotFoundException`. This catches the case where the
    category still exists in ShopWired but has been hidden from the storefront.

  This is the only test in the suite that guards the `64943` constant against
  drift — if the ShopWired best-sellers category is ever renumbered or deleted,
  the Sunday run will fail loudly (and be visible in Sentry / `storage/logs/laravel.log`)
  rather than silently mis-categorising products.

- **Job test**: copy the `UpdateProductFilterJob` test shape; assert
  `ProductFieldUpdateClient::update()` is called with the correct merged category
  list for both add and remove directions, and that the idempotency guard skips the
  call when live membership already matches `$shouldBeMember`.

### 2. Local smoke test (after implementation)

```bash
php artisan tinker --execute="App\\Infrastructure\\Jobs\\Catalog\\SyncBestSellersCategoryJob::dispatch();"
```

Check `storage/logs/laravel.log` for the orchestrator log lines and `bulk` queue
activity. Verify a handful of live products have category 64943 added/removed
correctly against ShopWired's admin UI (one product from each direction).

### 3. Quality gates

- `make lint` — Pint, PHPStan max, PHPArkitect (naming + layer), Deptrac, TLint.
- `make test` — full suite.
- Watch for: new interface must be in `Application/Contracts/Catalog/`, concrete repo
  in `Infrastructure/Catalog/Repositories/`, job in `Infrastructure/Jobs/Catalog/`,
  use case in `Application/Catalog/UseCases/` — all enforced by PHPArkitect.

---

## Out of scope

- Refactoring `AddProductToSaleUseCase` / `RemoveProductFromSaleUseCase` into a generic
  "add/remove product to category" primitive. Could be a follow-up if a third consumer
  appears; two uses don't yet justify extraction.
- Storing historical best-sellers membership changes (auditing). The popularity
  snapshots table already captures the underlying data; reconstructing membership
  history is derivable.
- Hooking the best-sellers sync into the snapshot job's completion (event-driven)
  rather than a daily schedule. Daily cron is simpler and gives drift protection
  against manual admin edits between snapshots.
