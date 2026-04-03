# Plan: Hourly Customer Rating Filter Sync

## Context

Product review ratings (from Reviews.io) are already synced to ShopWired custom fields via a two-stage daily pipeline. This feature adds a **third stage** that maps those ratings to ShopWired **product filters** — specifically a "Customer Rating" filter with values "4" and "4.5".

This enables shoppers to filter products by star rating (4+ stars, 4.5+ stars). A product with a 4.6 average gets both filter values; a 4.3 gets only "4"; below 4.0 gets neither.

**No local DB update** — the ShopWired webhook/product sync handles that. Worst case: correct data is re-pushed on the next hourly run.

---

## Components

### 1. Database Guard Test — `CustomerRatingFilterGroupGuardTest` (FIRST)

**File:** `tests/Integration/Catalog/CustomerRatingFilterGroupGuardTest.php`

**Pattern ref:** `tests/Integration/Persistence/RlsConnectionTest.php`

Write this first and run `make test`. If it fails, stop — the prerequisite data is missing. This protects the hardcoded optionNo `15` in the SQL view and use case.

```php
#[CoversNothing]
class CustomerRatingFilterGroupGuardTest extends TestCase
{
    #[Test]
    public function customer_rating_filter_group_exists_with_option_no_15(): void
    {
        $row = DB::connection('pgsql')
            ->table('shopwired.filter_groups')
            ->where('option_no', 15)
            ->first();

        $this->assertNotNull($row, 'Filter group with option_no=15 must exist — the rating filter sync view depends on it');
        $this->assertSame('Customer Rating', $row->title);
    }
}
```

Runs on every `make test` (pre-push). If someone deletes or renumbers this filter group in ShopWired and re-syncs, the push fails before broken code reaches the remote.

---

### 2. SQL View — `catalog.products_with_changed_rating_filters`

**File:** `database/migrations/{ts}_create_catalog_products_with_changed_rating_filters_view.php`

**Pattern ref:** `database/migrations/2026_03_31_110001_create_catalog_products_with_changed_ratings_view.php`

Everything in SQL — the view computes desired filter values, does the diff comparison, and returns only products needing updates. Repository is a trivial `SELECT *`:

```sql
CREATE OR REPLACE VIEW catalog.products_with_changed_rating_filters AS
WITH product_averages AS (
    SELECT
        product_skus.product_id,
        ROUND(
            SUM(r.average_rating * r.num_ratings)
            / NULLIF(SUM(r.num_ratings), 0),
            4
        ) AS weighted_average
    FROM (
        SELECT external_id AS product_id, sku
        FROM shopwired.products
        WHERE sku IS NOT NULL AND sku != ''
        UNION ALL
        SELECT product_external_id AS product_id, sku
        FROM shopwired.product_variations
        WHERE sku IS NOT NULL AND sku != ''
    ) product_skus
    LEFT JOIN reviews_io.product_ratings r ON r.sku = product_skus.sku
    GROUP BY product_skus.product_id
)
SELECT
    pa.product_id,
    CASE
        WHEN pa.weighted_average >= 4.5 THEN ARRAY['4', '4.5']
        WHEN pa.weighted_average >= 4.0 THEN ARRAY['4']
        ELSE ARRAY[]::text[]
    END AS desired_filter_values
FROM product_averages pa
JOIN shopwired.products p ON p.external_id = pa.product_id
WHERE COALESCE(p.filters->'15', '[]'::jsonb)
      IS DISTINCT FROM to_jsonb(
          CASE
              WHEN pa.weighted_average >= 4.5 THEN ARRAY['4', '4.5']
              WHEN pa.weighted_average >= 4.0 THEN ARRAY['4']
              ELSE ARRAY[]::text[]
          END
      );
```

**Key design:** optionNo `15` is hardcoded (matching `FilterGroupOptionNo::CustomerRating` enum). A guard test (§1) fails loudly if this optionNo doesn't exist in `filter_groups`, catching misconfiguration before any sync runs. Stricter than a title lookup which could silently match the wrong filter if titles are edited.

---

### 3. DTO — `ProductFilterChangeDTO`

**File:** `app/Application/Catalog/DTOs/ProductFilterChangeDTO.php`

```php
final readonly class ProductFilterChangeDTO {
    /** @param list<string> $desiredFilterValues */
    public function __construct(
        public IntId $productId,
        public int $optionNo,
        public array $desiredFilterValues,
    ) {}
}
```

The `optionNo` is injected by the repository (from `FilterGroupOptionNo::CustomerRating->value`) — it's opaque data in the Application layer.


---

### 5. Repository Interface

**File:** `app/Application/Contracts/Catalog/RatingFilterQueryRepositoryInterface.php`

```php
interface RatingFilterQueryRepositoryInterface {
    /**
     * @return list<ProductFilterChangeDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function getProductsWithChangedRatingFilters(): array;
}
```

No parameters — the view handles optionNo resolution internally. Returns empty if the "Customer Rating" filter group doesn't exist.

---

### 6. Repository Implementation

**File:** `app/Infrastructure/Catalog/Repositories/RatingFilterQueryRepository.php`

**Pattern ref:** `app/Infrastructure/ReviewsIo/Repositories/ChangedRatingQueryRepository.php`

Trivial query — all logic lives in the view:

```sql
SELECT product_id, desired_filter_values
FROM catalog.products_with_changed_rating_filters
```

Maps rows to `ProductFilterChangeDTO`, injecting the optionNo from the enum:

```php
new ProductFilterChangeDTO(
    productId: IntId::from($row->product_id),
    optionNo: FilterGroupOptionNo::CustomerRating->value,
    desiredFilterValues: /* pg array_to_json conversion */,
)
```

---

### 7. Enum — `FilterGroupOptionNo`

**File:** `app/Infrastructure/Shopwired/Enums/FilterGroupOptionNo.php`

Int-backed enum mapping known filter groups to their ShopWired optionNo. Used by the SQL view (hardcoded), the per-entity job, and the dispatcher.

```php
enum FilterGroupOptionNo: int
{
    case CustomerRating = 15;
}
```

The guard test (§1) protects this mapping — if the filter group is renumbered in ShopWired, the test fails on pre-push.

---

### 8. Use Case — `SyncRatingFiltersUseCase` (orchestrator)

**File:** `app/Application/Catalog/UseCases/SyncRatingFiltersUseCase.php`

**Pattern ref:** `app/Application/Shopwired/UseCases/DispatchProductFreeDeliveryJobsUseCase.php`

Dependencies:
- `RatingFilterQueryRepositoryInterface` — find products needing updates
- `CatalogSyncDispatcherInterface` — dispatch per-entity filter update jobs
- `LoggerInterface`

Flow:
1. `ratingFilterRepo->getProductsWithChangedRatingFilters()`
2. If empty → log info, return zero count
3. Loop: for each product, call `dispatcher->dispatchRatingFilterUpdate($change->productId, $change->optionNo, $values)`
   - For empty desiredFilterValues (rating < 4.0): pass `null` to remove the filter
4. Log + return count dispatched

The `optionNo` is opaque data — the use case passes it through without interpreting it.

---

### 8b. Dispatcher Interface

**File:** `app/Application/Contracts/Catalog/CatalogSyncDispatcherInterface.php`

```php
interface CatalogSyncDispatcherInterface {
    /** @param list<string>|null $values Filter values to set, or null to remove */
    public function dispatchRatingFilterUpdate(IntId $productId, int $optionNo, ?array $values): void;
}
```

---

### 8c. Dispatcher Implementation

**File:** `app/Infrastructure/Catalog/Dispatchers/QueuedCatalogSyncDispatcher.php`

**Pattern ref:** `app/Infrastructure/Shopwired/Dispatchers/QueuedShopwiredSyncDispatcher.php`

```php
public function dispatchRatingFilterUpdate(IntId $productId, int $optionNo, ?array $values): void
{
    UpdateProductRatingFilterJob::dispatch($productId, $optionNo, $values);
}
```

---

### 8d. Per-Entity Job — `UpdateProductRatingFilterJob`

**File:** `app/Infrastructure/Jobs/Catalog/UpdateProductRatingFilterJob.php`

**Pattern ref:** `app/Infrastructure/Jobs/Shopwired/SetProductFreeDeliveryJob.php`

Per-product job that calls the ShopWired API. Runs on the `bulk` queue with rate limiting for API budget control.

- Queue: `QueueName::Bulk`
- **NOT** `ShouldBeUnique` (multiple products dispatched concurrently)
- tries: 6, maxExceptions: 3, timeout: 60, failOnTimeout: true
- backoff: `[60, 300, 900]`
- retryUntil: `now()->addHours(4)->toDateTimeImmutable()`
- Middleware: `ServiceRateLimiter::shopwiredApiBulk()`, `ServiceCircuitBreaker::shopwired()`, `HandleApiExceptions`

```php
public function __construct(
    public readonly IntId $productId,
    public readonly int $optionNo,
    public readonly ?array $filterValues,
) {
    $this->onQueue(QueueName::Bulk->value);
}

public function handle(ProductUpdateClientInterface $updateClient): void
{
    $updateClient->updateFilters(
        $this->productId->value,
        [$this->optionNo => $this->filterValues],
    );
}
```

---

### 9. Orchestrator Job — `SyncRatingFiltersJob`

**File:** `app/Infrastructure/Jobs/Catalog/SyncRatingFiltersJob.php`

Orchestrator only — queries DB and dispatches per-entity jobs. Does NOT call the ShopWired API directly, so no rate limiter or circuit breaker needed.

- Queue: `QueueName::Low`
- `ShouldBeUnique`, uniqueFor: 1200 (20 min)
- tries: 4, maxExceptions: 2, timeout: 120, failOnTimeout: true
- backoff: `[30, 60]`
- retryUntil: `now()->addMinutes(45)->toDateTimeImmutable()` — short window, next hourly run picks up any missed work
- Middleware: `HandleApiExceptions` (for DB exceptions only)
- `handle()` injects `SyncRatingFiltersUseCase` and calls `execute()`

---

### 10. Service Provider — `CatalogServiceProvider`

**File:** `app/Providers/CatalogServiceProvider.php`

New deferred provider. Binds:
- `RatingFilterQueryRepositoryInterface` → `RatingFilterQueryRepository` (scoped)
- `CatalogSyncDispatcherInterface` → `QueuedCatalogSyncDispatcher` (scoped)

Register in `bootstrap/providers.php`.

---

### 11. Schedule — Hourly Rating Filter Sync

**File:** `app/Providers/Schedule/ReviewsIoScheduleServiceProvider.php` (modify)

Add a separate `registerRatingFilterSchedule()` method called from `boot()`. This is **independent** of the daily 2-stage ratings pipeline — it reads the same ratings data but runs on its own hourly cadence:

```php
private function registerRatingFilterSchedule(): void
{
    // Independent hourly sync: maps product ratings to ShopWired filter values
    // Reads from reviews_io.product_ratings (populated by Stage 1) but runs independently
    Schedule::job(new SyncRatingFiltersJob())
        ->name('sync-rating-filters')
        ->hourly()
        ->timezone('Europe/London')
        ->onOneServer()
        ->withoutOverlapping(30);
}
```

---

### 12. Unit Test

**File:** `tests/Unit/Application/Catalog/UseCases/SyncRatingFiltersUseCaseTest.php`

Scenarios:
1. No products with changed filters → returns zero result
2. Products updated successfully (mix of add/remove filter values)
3. Partial failure — some products throw `ResourceNotAvailableException`
4. Correct optionNo 15 passed to `updateFilters()`

---

## File Summary

| # | Action | File |
|---|--------|------|
| 1 | Create | `tests/Integration/Catalog/CustomerRatingFilterGroupGuardTest.php` |
| 2 | Create | `database/migrations/{ts}_create_catalog_products_with_changed_rating_filters_view.php` |
| 3 | Create | `app/Infrastructure/Shopwired/Enums/FilterGroupOptionNo.php` |
| 4 | Create | `app/Application/Catalog/DTOs/ProductFilterChangeDTO.php` |
| 5 | Create | `app/Application/Contracts/Catalog/RatingFilterQueryRepositoryInterface.php` |
| 6 | Create | `app/Application/Contracts/Catalog/CatalogSyncDispatcherInterface.php` |
| 7 | Create | `app/Infrastructure/Catalog/Repositories/RatingFilterQueryRepository.php` |
| 8 | Create | `app/Infrastructure/Catalog/Dispatchers/QueuedCatalogSyncDispatcher.php` |
| 9 | Create | `app/Application/Catalog/UseCases/SyncRatingFiltersUseCase.php` |
| 10 | Create | `app/Infrastructure/Jobs/Catalog/UpdateProductRatingFilterJob.php` (per-entity, bulk queue) |
| 11 | Create | `app/Infrastructure/Jobs/Catalog/SyncRatingFiltersJob.php` (orchestrator) |
| 12 | Create | `app/Providers/CatalogServiceProvider.php` |
| 13 | Modify | `app/Providers/Schedule/ReviewsIoScheduleServiceProvider.php` |
| 14 | Modify | `bootstrap/providers.php` |
| 15 | Create | `tests/Unit/Application/Catalog/UseCases/SyncRatingFiltersUseCaseTest.php` |
| 16 | Fix | `app/Infrastructure/Jobs/ReviewsIo/UpdateShopwiredRatingsJob.php` — `::reviewsio()` → `::shopwired()` |

## Implementation Order

1. **Guard test first** — Write `CustomerRatingFilterGroupGuardTest`, run `make test`. **If this fails, stop.**
2. Migration (SQL view)
3. Enum + DTO + Interfaces
4. Repository + Dispatcher implementations
5. Use case
6. Per-entity job + Orchestrator job
7. Service provider + Schedule + bootstrap
8. Unit test
9. Bug fix (UpdateShopwiredRatingsJob circuit breaker)

## Verification

1. `make lint` — all linters pass
2. `make test` — all tests pass (unit + integration + guard)
3. Smoke test: `php artisan tinker --execute="App\Infrastructure\Jobs\Catalog\SyncRatingFiltersJob::dispatch();"` — dispatches to local queue worker, check `storage/logs/laravel.log` for output
