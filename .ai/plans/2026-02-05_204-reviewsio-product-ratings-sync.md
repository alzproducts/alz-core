# Reviews.io Product Ratings Sync - Implementation Plan

## Overview

Two-stage sync pipeline:
1. **Stage 1**: Reviews.io API → Local `reviews_io.product_ratings` table
2. **Stage 2**: Local DB → ShopWired custom fields (`average_rating`, `num_ratings`)

## Design Decisions

| Decision | Choice |
|----------|--------|
| SKU Source | New `ProductRepositoryInterface::getAllSkus()` (single SQL query) |
| No-review SKUs | Store with `average_rating=NULL`, `num_ratings=0` |
| History | Latest only (upsert by SKU) |
| Custom field names | `average_rating`, `num_ratings` (legacy compatible) |
| Triggers | Daily scheduled job + manual artisan command |
| Job structure | Single combined job (Stage 1 → Stage 2) |

## Existing Classes to Reuse

| Class | Location | Notes |
|-------|----------|-------|
| `ProductRating` | `Domain/Catalog/Product/ValueObjects/` | Value object with `sku`, `averageRating`, `numRatings` |
| `ReviewsIoClientInterface` | `Application/Contracts/` | Already has `getProductRatingBatch()` |
| `ReviewsIoClient` | `Infrastructure/ReviewsIo/` | Implements batch fetching, returns `ProductRating[]` |
| `ProductRepositoryInterface` | `Application/Contracts/Shopwired/` | **Extend** with new `getAllSkus()` method |

## New Method on Existing Interface

### `ProductRepositoryInterface::getAllSkus()` (ADD)

Add to existing interface alongside `getAllExternalIds()` and `getAllVariationExternalIds()`:

```php
/**
 * Get all unique SKUs from products and variations.
 *
 * Returns distinct SKUs from both master products and variations.
 * Used for bulk operations (Reviews.io sync, feed generation, etc.)
 *
 * @return list<string> Unique SKU values
 *
 * @throws DatabaseOperationFailedException On query failure
 * @throws ExternalServiceUnavailableException When database temporarily unavailable
 */
public function getAllSkus(): array;
```

### Implementation (`EloquentProductRepository`)

```php
public function getAllSkus(): array
{
    return $this->database
        ->query()
        ->selectRaw('DISTINCT sku')
        ->from('shopwired.products')
        ->whereNotNull('sku')
        ->union(
            $this->database
                ->query()
                ->selectRaw('DISTINCT sku')
                ->from('shopwired.product_variations')
                ->whereNotNull('sku')
        )
        ->pluck('sku')
        ->all();
}
```

---

## File Structure

```
app/
├── Application/
│   ├── Contracts/ReviewsIo/
│   │   └── ProductRatingRepositoryInterface.php     # NEW - reviews_io schema only
│   └── ReviewsIo/
│       ├── UseCases/
│       │   ├── SyncProductRatingsUseCase.php        # NEW - Stage 1
│       │   └── UpdateShopwiredRatingsUseCase.php    # NEW - Stage 2
│       └── Results/
│           └── RatingsUpdateResult.php              # NEW
│
├── Infrastructure/ReviewsIo/
│   ├── Models/
│   │   └── ProductRatingModel.php                   # NEW
│   └── Repositories/
│       └── EloquentProductRatingRepository.php      # NEW
│
├── Presentation/
│   ├── Console/Commands/
│   │   └── SyncProductRatingsCommand.php            # NEW
│   └── Jobs/ReviewsIo/
│       └── SyncProductRatingsJob.php                # NEW
│
└── Providers/Schedule/
    └── ReviewsIoScheduleServiceProvider.php         # NEW
```

---

## Database

### Migration 1: Schema (with Supabase permissions)
```php
// database/migrations/2026_02_05_000001_create_reviews_io_schema.php
return new class extends Migration {
    public function up(): void
    {
        $schemaExists = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'reviews_io') as exists",
        );

        if ($schemaExists !== null && $schemaExists->exists) {
            return;
        }

        DB::statement('CREATE SCHEMA IF NOT EXISTS reviews_io');

        // Grant schema usage to Supabase roles
        DB::statement('GRANT USAGE ON SCHEMA reviews_io TO authenticated, service_role');

        // Set default privileges for tables created in this schema
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA reviews_io GRANT ALL ON TABLES TO service_role');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA reviews_io GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO authenticated');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA reviews_io GRANT USAGE, SELECT ON SEQUENCES TO service_role');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA reviews_io GRANT USAGE, SELECT ON SEQUENCES TO authenticated');
    }

    public function down(): void
    {
        $hasTables = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'reviews_io') as exists",
        );

        if ($hasTables === null || ! $hasTables->exists) {
            $this->safeRevoke('ALTER DEFAULT PRIVILEGES IN SCHEMA reviews_io REVOKE ALL ON TABLES FROM service_role');
            $this->safeRevoke('ALTER DEFAULT PRIVILEGES IN SCHEMA reviews_io REVOKE ALL ON TABLES FROM authenticated');
            $this->safeRevoke('ALTER DEFAULT PRIVILEGES IN SCHEMA reviews_io REVOKE ALL ON SEQUENCES FROM service_role');
            $this->safeRevoke('ALTER DEFAULT PRIVILEGES IN SCHEMA reviews_io REVOKE ALL ON SEQUENCES FROM authenticated');
            $this->safeRevoke('REVOKE USAGE ON SCHEMA reviews_io FROM authenticated, service_role');
            DB::statement('DROP SCHEMA IF EXISTS reviews_io');
        }
    }

    private function safeRevoke(string $sql): void
    {
        try {
            DB::statement($sql);
        } catch (Exception) {
            // Role/schema may not exist during rollback
        }
    }
};
```

### Migration 2: Table
```php
// database/migrations/2026_02_05_000002_create_reviews_io_product_ratings_table.php
Schema::create('reviews_io.product_ratings', function (Blueprint $table) {
    $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
    $table->string('sku', 255)->unique();
    $table->decimal('average_rating', 3, 2)->nullable();  // NULL = no reviews
    $table->unsignedInteger('num_ratings')->default(0);
    $table->timestampTz('fetched_at');
    $table->timestampsTz();
});
```

---

## Stage 1: Reviews.io → DB

### Flow (Clean Architecture)
1. UseCase calls `ProductRepositoryInterface::getAllSkus()` (new method)
2. Chunk into batches of 100 (Reviews.io API limit)
3. For each batch: call `ReviewsIoClientInterface::getProductRatingBatch($skus)` (existing)
4. Track which SKUs returned vs queried (missing = no reviews)
5. Upsert all ratings via `ProductRatingRepositoryInterface::upsertMany()` (new)

### Key Method: `SyncProductRatingsUseCase::execute()`
```php
public function __construct(
    private ProductRepositoryInterface $productRepository,      // EXISTING - for getAllSkus()
    private ReviewsIoClientInterface $reviewsIoClient,          // EXISTING - for ratings
    private ProductRatingRepositoryInterface $ratingRepository, // NEW - for storage
) {}

public function execute(): SyncResult
{
    // Step 1: Get all SKUs (single DB query via new method)
    $allSkus = $this->productRepository->getAllSkus();

    $fetched = 0;
    $saved = 0;

    // Step 2: Batch fetch from Reviews.io (via existing client)
    foreach (array_chunk($allSkus, 100) as $skuBatch) {
        $ratings = $this->reviewsIoClient->getProductRatingBatch($skuBatch);

        // Map to upsert format, mark missing SKUs as NULL rating
        $rows = $this->prepareUpsertRows($skuBatch, $ratings);

        // Step 3: Store in reviews_io schema (via new repository)
        $result = $this->ratingRepository->upsertMany($rows);
        $fetched += count($skuBatch);
        $saved += $result->saved;
    }

    return new SyncResult($fetched, $saved, 0);
}
```

---

## Stage 2: DB → ShopWired

### Flow
1. Iterate ShopWired products (with variations eager-loaded)
2. For each product: collect all SKUs (master + variants)
3. Query `reviews_io.product_ratings` for those SKUs
4. Calculate weighted average: `sum(rating × count) / total_count`
5. Compare against current custom fields (skip if unchanged)
6. Call `ProductUpdateClient::updateCustomFields()` if changed

### Weighted Average Calculation
```php
private function calculateWeightedAverage(array $ratings): AggregatedRating
{
    $totalRatings = 0;
    $weightedSum = 0.0;

    foreach ($ratings as $rating) {
        if ($rating->averageRating !== null && $rating->numRatings > 0) {
            $weightedSum += $rating->averageRating * $rating->numRatings;
            $totalRatings += $rating->numRatings;
        }
    }

    return new AggregatedRating(
        averageRating: $totalRatings > 0 ? round($weightedSum / $totalRatings, 2) : null,
        numRatings: $totalRatings,
    );
}
```

### Change Detection
- Compare new values against `$product->customFields['average_rating']` and `['num_ratings']`
- Convert to strings for comparison (ShopWired stores as strings)
- Products with no reviews → `'0'` for both fields

---

## Job Configuration

```php
final class SyncProductRatingsJob implements ShouldBeUnique, ShouldQueue
{
    public int $tries = 3;
    public int $timeout = 1800;       // 30 minutes
    public int $uniqueFor = 2100;     // 35 minutes
    public array $backoff = [60, 300, 900];

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function uniqueId(): string
    {
        return 'sync-product-ratings';
    }

    public function handle(...): void
    {
        // Stage 1
        $syncResult = $syncUseCase->execute();

        // Stage 2
        $updateResult = $updateUseCase->execute();
    }
}
```

---

## Schedule

```php
// ReviewsIoScheduleServiceProvider
Schedule::job(new SyncProductRatingsJob())
    ->name('sync-product-ratings-daily')
    ->dailyAt('03:00')
    ->timezone('Europe/London')
    ->onOneServer()
    ->withoutOverlapping(35);
```

---

## Interfaces

### ProductRatingRepositoryInterface (NEW)
**Location**: `app/Application/Contracts/ReviewsIo/`

Only handles `reviews_io.product_ratings` table — no cross-schema queries.

```php
interface ProductRatingRepositoryInterface
{
    /**
     * Upsert ratings by SKU (insert or update on conflict).
     * @param list<ProductRating> $ratings
     */
    public function upsertMany(array $ratings): UpsertResult;

    /**
     * Get ratings for specific SKUs (for Stage 2 weighted average).
     * @param list<string> $skus
     * @return list<ProductRating>
     */
    public function getBySkus(array $skus): array;
}
```

### RatingsUpdateResult (for Stage 2)
```php
final readonly class RatingsUpdateResult
{
    public function __construct(
        public int $processed,
        public int $updated,
        public int $skipped,
        public int $failed,
        public array $failedProductIds = [],
    ) {}
}
```

### UpsertResult (for Stage 1)
```php
final readonly class UpsertResult
{
    public function __construct(
        public int $inserted,
        public int $updated,
    ) {}
}
```

---

## Error Handling

| Error Type | Stage 1 | Stage 2 |
|------------|---------|---------|
| API unavailable | Bubble up → job retry | Bubble up → job retry |
| Invalid response | Fail job (permanent) | N/A |
| Individual product fail | N/A | Log, continue, track in result |
| Product not found (404) | N/A | Log warning, continue |

---

## Implementation Order

1. [ ] Add `getAllSkus()` to `ProductRepositoryInterface` + `EloquentProductRepository`
2. [ ] Migrations (schema with Supabase perms + table)
3. [ ] `ProductRatingModel` (Eloquent model for `reviews_io.product_ratings`)
4. [ ] `ProductRatingRepositoryInterface` (contract — reviews_io schema only)
5. [ ] `UpsertResult` (result object for Stage 1)
6. [ ] `EloquentProductRatingRepository` (implementation)
7. [ ] `SyncProductRatingsUseCase` (Stage 1 — uses getAllSkus + ReviewsIoClient)
8. [ ] `RatingsUpdateResult` (result object for Stage 2)
9. [ ] `UpdateShopwiredRatingsUseCase` (Stage 2)
10. [ ] `SyncProductRatingsJob` (combined job)
11. [ ] `SyncProductRatingsCommand` (artisan command)
12. [ ] `ReviewsIoScheduleServiceProvider` (schedule)
13. [ ] Service provider bindings (just the new rating repository)
14. [ ] Tests

---

## Verification

1. **Stage 1 test**: Run `php artisan reviews:sync-ratings`, check `reviews_io.product_ratings` has rows
2. **Stage 2 test**: Check a ShopWired product's custom fields via API or admin
3. **Idempotency**: Run twice, verify second run shows mostly "skipped" (no changes)
4. **No reviews**: Verify products without reviews get `average_rating=0`, `num_ratings=0` in ShopWired

---

## Reference Files

### Existing (REUSE)
- `app/Domain/Catalog/Product/ValueObjects/ProductRating.php` - **Reuse** value object
- `app/Application/Contracts/ReviewsIoClientInterface.php` - **Existing** `getProductRatingBatch()`
- `app/Infrastructure/ReviewsIo/ReviewsIoClient.php` - **Existing** implementation

### Existing (EXTEND)
- `app/Application/Contracts/Shopwired/ProductRepositoryInterface.php` - **Add** `getAllSkus()` method
- `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php` - **Add** implementation

### Patterns to Follow
- `database/migrations/2026_01_16_100000_create_linnworks_schema.php` - Schema with Supabase permissions
- `app/Application/Shopwired/UseCases/SyncProductsUseCase.php` - Batch sync pattern
- `app/Infrastructure/Shopwired/Models/ProductModel.php` - Model with schema prefix
- `app/Infrastructure/Shopwired/Clients/ProductUpdateClient.php` - Custom field updates
- `app/Presentation/Jobs/Shopwired/SyncShopwiredOrdersJob.php` - Job pattern
