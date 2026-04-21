# Plan: Refresh endpoints for Category, Brand, and full Product catalogue

## Context

**Why.** Today only `POST /products/{productId}/refresh` exists. Operators have no way to pull a fresh copy of a single category/brand from ShopWired when the webhook missed or the cached row looks wrong, and there is no way to kick the nightly full-catalogue refresh on-demand — they have to wait for the 08:00/09:00 UK scheduled runs.

**What.** Add five endpoints. **Per the user's calibration, only the full product+stock refresh is async** — categories/brands are small enough (one Shopwired "list-all" call each) to run inline.

| Method | Route | Style | Status | Notes |
|---|---|---|---|---|
| POST | `/categories/{categoryId}/refresh` | sync | 204 | Mirrors product single-item refresh; fetch one + save |
| POST | `/brands/{brandId}/refresh`          | sync | 204 | Same pattern |
| POST | `/categories/refresh`                | sync | 204 | Calls existing `SyncCategoriesUseCase`, discards result; success = empty body, failure = exception → global handler |
| POST | `/brands/refresh`                    | sync | 204 | Same pattern with `SyncBrandsUseCase` |
| POST | `/products/refresh`                  | async | 202 | Dispatches `SyncShopwiredProductsJob` + `SyncLinnworksStockItemsJob`; body = `{message, jobs_dispatched, estimated_duration_seconds: 120}` |

**Scope decision: "full product refresh" = shopwired products table + Linnworks stock items table only.** We deliberately skip orders/customers/PO backfills — per user direction, "full refresh" for the product list means "resync the two tables that back the product API" and nothing else.

**Calibration decisions confirmed with user:**
- Single-item: sync 204 (matches `ProductUpdateController::refresh`).
- Bulk categories/brands: also sync — one list-all call + bulk upsert, roughly 1–3 s wall time.
- Bulk products+stock: async, `estimated_duration_seconds = 120`.
- No rate limiting — rely on the existing `ShouldBeUnique` guard on the two jobs.

---

## Architecture

```
Controller (Presentation)
    → Application use case
        → sync single: ClientInterface::getXById + RepositoryInterface::save    (new use case)
        → sync bulk:   existing SyncCategoriesUseCase / SyncBrandsUseCase        (reuse)
        → async bulk:  ShopwiredSyncDispatcher + LinnworksSyncDispatcher         (dispatch only)
```

Async refresh **just dispatches existing scheduled jobs** — no new jobs, no new sync logic. Both jobs already have `ShouldBeUnique` guards (`SyncShopwiredProductsJob::uniqueFor = 1200`, same on the Linnworks one via `uniqueFor = 4200`), so if a scheduled run is mid-flight the on-demand dispatch is silently deduplicated. Correct behaviour.

Single-item refresh calls ShopWired + saves synchronously — simpler than `RefreshProductViewUseCase` because categories/brands don't touch Linnworks stock.

---

## Files to add

### 1. Application — Single-item use cases (new)

**`app/Application/Catalog/UseCases/RefreshCategoryViewUseCase.php`**
```php
final readonly class RefreshCategoryViewUseCase
{
    public function __construct(
        private CategoryClientInterface $client,
        private CategoryRepositoryInterface $repository,
        private LoggerInterface $logger,
    ) {}

    public function execute(IntId $categoryId): void
    {
        $this->logger->info('Refreshing category', ['category_id' => $categoryId->value]);
        $category = $this->client->getCategoryById($categoryId->value);
        $this->repository->save($category);
        $this->logger->info('Category refresh complete', ['category_id' => $categoryId->value]);
    }
}
```
`@throws` must cover the union of what `CategoryClientInterface::getCategoryById()` + `RepositoryWriteInterface::save()` declare — 7 exceptions in total: `InvalidApiRequestException`, `AuthenticationExpiredException`, `ResourceNotAvailableException`, `ExternalServiceUnavailableException`, `InvalidApiResponseException`, `DatabaseOperationFailedException`, `DuplicateRecordException`. **Do NOT copy from `SyncShopwiredCategoryJob::handle()` — that job's `@throws` only declares the two DB-layer exceptions (under-declared; the API-layer exceptions get eaten by job middleware).** Pull the authoritative list from the interface docblocks.

**`app/Application/Catalog/UseCases/RefreshBrandViewUseCase.php`** — mirror image for brands.

*(Optional future sweep, out of scope: the existing `SyncShopwiredCategoryJob` / `SyncShopwiredBrandJob` per-entity jobs duplicate these three lines. They can later be refactored to call the new use case. Don't do it in this PR.)*

### 2. Application — Async bulk use case (new, one only)

**`app/Application/Catalog/UseCases/RefreshAllProductsUseCase.php`**
```php
final readonly class RefreshAllProductsUseCase
{
    public const int ESTIMATED_DURATION_SECONDS = 120;

    public function __construct(
        private ShopwiredSyncDispatcherInterface $shopwiredDispatcher,
        private LinnworksSyncDispatcherInterface $linnworksDispatcher,
    ) {}

    public function execute(): int
    {
        $this->shopwiredDispatcher->dispatchAllProductsSync();
        $this->linnworksDispatcher->dispatchFullStockItemsSync(); // already exists
        return 2;
    }
}
```

**No new bulk use cases for categories/brands** — reuse existing `SyncCategoriesUseCase` / `SyncBrandsUseCase` directly from the controller (they already return a `SyncResult`).

### 3. Application — Dispatcher interface addition (single method)

**`app/Application/Contracts/Shopwired/ShopwiredSyncDispatcherInterface.php`** *(edit)* — add **one** method:
```php
public function dispatchAllProductsSync(): void;
```

Linnworks interface already has `dispatchFullStockItemsSync()` — reuse as-is (`app/Application/Contracts/Linnworks/LinnworksSyncDispatcherInterface.php:19`).

### 4. Infrastructure — Dispatcher implementation

**`app/Infrastructure/Shopwired/Dispatchers/QueuedShopwiredSyncDispatcher.php`** *(edit)* — one new one-liner:
```php
public function dispatchAllProductsSync(): void
{
    SyncShopwiredProductsJob::dispatch();
}
```

*(Verified: `QueuedShopwiredSyncDispatcher` is the only implementation — no test doubles to update.)*

### 5. Presentation — Response DTO (just one)

Only the async 202 endpoint needs a structured body. Bulk category/brand refresh return 204 with no body — if something goes wrong the use case throws and the global handler renders the error envelope.

**`AsyncRefreshAcceptedResponseDTO`** *(new)* — for the 202 product+stock endpoint, under `app/Presentation/Http/Api/Responses/` (matches existing `BulkUpdateResponseDTO` location):
```php
final class AsyncRefreshAcceptedResponseDTO extends Data
{
    public function __construct(
        public string $message,
        public int $jobsDispatched,
        public int $estimatedDurationSeconds,
    ) {}
}
```

Verify Spatie snake-case mapping is applied globally (check `config/data.php` or the `DataCollection` defaults). If it isn't, annotate each class with `#[MapOutputName(SnakeCaseMapper::class)]` so `jobsDispatched` renders as `jobs_dispatched`, etc.

### 6. Presentation — Controller methods

**`app/Presentation/Http/Api/Controllers/CategoryUpdateController.php`** *(edit)* — add two methods + inject two use cases:
```php
public function __construct(
    private UpdateCategoryFieldsUseCase $fieldsUseCase,
    private UpdateCategoryCustomFieldsUseCase $customFieldsUseCase,
    private RefreshCategoryViewUseCase $refreshUseCase,          // new
    private SyncCategoriesUseCase $syncAllUseCase,               // existing, newly injected
) {}

public function refresh(int $categoryId): JsonResponse
{
    $this->refreshUseCase->execute(IntId::from($categoryId));
    return new JsonResponse(null, Response::HTTP_NO_CONTENT);
}

public function refreshAll(): JsonResponse
{
    $this->syncAllUseCase->execute(); // discard SyncResult; exceptions bubble to global handler
    return new JsonResponse(null, Response::HTTP_NO_CONTENT);
}
```

**`app/Presentation/Http/Api/Controllers/BrandUpdateController.php`** *(edit)* — mirror image (inject `SyncBrandsUseCase`, same 204 pattern).

**`app/Presentation/Http/Api/Controllers/ProductUpdateController.php`** *(edit)* — add one method + inject `RefreshAllProductsUseCase`:
```php
public function refreshAll(): JsonResponse
{
    $dispatched = $this->refreshAllUseCase->execute();
    return new JsonResponse(
        (new AsyncRefreshAcceptedResponseDTO(
            message: 'Product & stock refresh queued',
            jobsDispatched: $dispatched,
            estimatedDurationSeconds: RefreshAllProductsUseCase::ESTIMATED_DURATION_SECONDS,
        ))->toArray(),
        Response::HTTP_ACCEPTED,
    );
}
```
Existing `refresh()` at line 94 stays untouched.

### 7. Routes

**`routes/api.php`** *(edit)* — add inside the existing authenticated group:
```php
// after existing line 148 (single product refresh)
Route::post('products/refresh', [ProductUpdateController::class, 'refreshAll']);

// inside the category block (after line 163)
Route::post('categories/refresh', [CategoryUpdateController::class, 'refreshAll']);
Route::post('categories/{categoryId}/refresh', [CategoryUpdateController::class, 'refresh'])
    ->whereNumber('categoryId');

// inside the brand block (after line 173)
Route::post('brands/refresh', [BrandUpdateController::class, 'refreshAll']);
Route::post('brands/{brandId}/refresh', [BrandUpdateController::class, 'refresh'])
    ->whereNumber('brandId');
```
`whereNumber` on `{categoryId}`/`{brandId}` means Laravel won't match the literal `refresh` segment to the parameter route — no ordering hazard.

---

## Reused existing code (no changes)

| Component | Path |
|---|---|
| `CategoryClientInterface::getCategoryById()` | `app/Application/Contracts/Shopwired/CategoryClientInterface.php` |
| `BrandClientInterface::getBrandById()` | `app/Application/Contracts/Shopwired/BrandClientInterface.php` |
| `CategoryRepositoryInterface::save()` / `BrandRepositoryInterface::save()` | same folder |
| `SyncCategoriesUseCase` | `app/Application/Shopwired/UseCases/SyncCategoriesUseCase.php` |
| `SyncBrandsUseCase` | `app/Application/Shopwired/UseCases/SyncBrandsUseCase.php` |
| `SyncResult` | `app/Application/Results/SyncResult.php` |
| `SyncShopwiredProductsJob` (ShouldBeUnique, timeout 900) | `app/Infrastructure/Jobs/Shopwired/SyncShopwiredProductsJob.php` |
| `SyncLinnworksStockItemsJob` (ShouldBeUnique, timeout 3600) | `app/Infrastructure/Jobs/Linnworks/SyncLinnworksStockItemsJob.php` |
| `LinnworksSyncDispatcherInterface::dispatchFullStockItemsSync()` | `app/Application/Contracts/Linnworks/LinnworksSyncDispatcherInterface.php:19` |

---

## Tests to add

Follow `tests/TestingStrategy.md`. Minimum viable:

**Unit (use cases)** — `tests/Unit/Application/Catalog/UseCases/`
- `RefreshCategoryViewUseCaseTest` — mock client + repo; assert `save()` called with the category returned by `getCategoryById()`; assert logger info→info.
- `RefreshBrandViewUseCaseTest` — mirror.
- `RefreshAllProductsUseCaseTest` — spy both dispatchers; assert one call each; return value = 2.

No new unit tests for `SyncCategoriesUseCase` / `SyncBrandsUseCase` — they're unchanged.

**Feature (HTTP)** — `tests/Feature/Api/`
- `CategoryRefreshEndpointTest`:
  - `POST /categories/123/refresh` → 204; client mock returns a fixture category; assert repo `save()` invoked.
  - `POST /categories/refresh` → 204; client mock's `listAllCategories()` returns a fixture list; assert repo `saveMany()` invoked with that list.
  - Sad path: when `SyncCategoriesUseCase` throws `RuntimeException` (zero categories), the endpoint surfaces a 500 via the global handler.
- `BrandRefreshEndpointTest` — mirror.
- `ProductRefreshAllEndpointTest`:
  - `POST /products/refresh` → 202 with `{message, jobs_dispatched: 2, estimated_duration_seconds: 120}`.
  - `Queue::fake()` + `Queue::assertPushed(SyncShopwiredProductsJob::class)` + `Queue::assertPushed(SyncLinnworksStockItemsJob::class)`.
- Auth: use local bypass header per CLAUDE.md "Local API Testing"; copy the auth-fail case style from existing `ProductUpdateController` feature tests.

Existing `RefreshProductViewUseCase` tests unchanged.

---

## Verification

Octane + queue listener already running locally via `.run/Queue.run.xml`.

```bash
# Single category (sync)
curl -i -X POST http://127.0.0.1:8000/api/categories/42/refresh \
  -H "X-Local-Bypass: $API_BYPASS_SECRET"
# Expect: HTTP/1.1 204 No Content

# Bulk categories (sync)
curl -i -X POST http://127.0.0.1:8000/api/categories/refresh \
  -H "X-Local-Bypass: $API_BYPASS_SECRET"
# Expect: HTTP/1.1 204 No Content

# Bulk brands (sync)
curl -i -X POST http://127.0.0.1:8000/api/brands/refresh \
  -H "X-Local-Bypass: $API_BYPASS_SECRET"
# Expect: HTTP/1.1 204 No Content

# Full product + stock (async)
curl -i -X POST http://127.0.0.1:8000/api/products/refresh \
  -H "X-Local-Bypass: $API_BYPASS_SECRET"
# Expect: 202 + {"message":"Product & stock refresh queued","jobs_dispatched":2,"estimated_duration_seconds":120}
```

After the 202, tail `storage/logs/laravel.log` to watch `SyncShopwiredProductsJob` + `SyncLinnworksStockItemsJob` run. Re-POST immediately while the first run is in-flight — `ShouldBeUnique` should drop the duplicate (no second run in logs, endpoint still returns 202).

DB sanity after a full product refresh:
- `shopwired.products.updated_at` bumped on rows that changed.
- `linnworks.stock_items.updated_at` bumped.

Automated:
```bash
make lint
make test
```

---

## Risk / caveats

- **Concurrency guard stack.** The consumer-API route group already carries `throttle:api` (routes/api.php:134), so raw spam is rate-limited at the HTTP layer. On top of that, `ShouldBeUnique` on both bulk jobs dedupes concurrent dispatches. Net behaviour: the 202 means "dispatch attempted", not "a new job is queued" — if a scheduled or earlier manual run is in flight, the dispatch is a no-op and the endpoint still returns 202. Worth flagging in the endpoint docblock so the frontend doesn't assume each 202 corresponds to a fresh run.
- **`estimated_duration_seconds = 120` is aggressive.** Observed scheduled runtimes are ~2–5 min for each of the two jobs. Since they run on the same `low` queue and Horizon may serialise them, real wall time can exceed 120 s. Per user decision, use 120 s as the displayed estimate — if the frontend ever shows a blown-past-estimate state, revisit by deriving from Horizon history.
- **Error shape for bulk sync.** `SyncCategoriesUseCase` / `SyncBrandsUseCase` throw `RuntimeException` on zero rows (treated as unexpected) — this surfaces as a 500 via the global `InternalApiExceptionMapper`. Frontend treats any non-204 as failure. Don't try to handle it specially in the controller.
- **Auth/approval gate.** Route placement above keeps all new endpoints in the same Supabase JWT + approval-gate middleware group that already protects `products/{id}/refresh`. Verify during implementation by confirming the route group context.
