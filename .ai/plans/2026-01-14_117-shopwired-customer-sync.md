# Plan: ShopWired Customer Sync Job

## Summary
Sync ~60k customers from ShopWired to local database using weekly full-refresh strategy with **memory-efficient batch processing**.

## Key Findings (from investigation)
- **60k customers / 100 per page = 600 API calls = ~10 minutes** at 60 req/min rate limit
- API supports sorting: `created`, `created_asc`, `created_desc`, `name`, `name_desc`, `company`, `company_desc`
- Weekly full sync is simplest approach - catches new customers AND updates
- **Memory constraint**: Cannot load 60k customers into memory at once - need generator/streaming pattern

## Architecture Decision: Generator Pattern

**Problem**: Original `fetchAll()` pattern loads all 60k customers into memory before saving.

**Solution**: Generator that yields batches (pages) of ~100 customers, allowing save-as-you-go:

```php
// Use case loops over batches, saves each, aggregates stats
foreach ($this->client->iterateAllCustomerBatches() as $batch) {
    $saveResult = $this->repository->saveMany($batch);
    // aggregate stats...
}
```

**Why batches, not individual items?** Keeps "when to save" decision in Application layer. Repository receives ~100 customers at a time for efficient batch inserts.

---

## Implementation Steps

### 1. Add CustomerSort Enum ✅
**File:** `app/Infrastructure/Shopwired/Enums/CustomerSort.php`

```php
enum CustomerSort: string
{
    case Created = 'created';
    case CreatedAsc = 'created_asc';
    case CreatedDesc = 'created_desc';
    case Name = 'name';
    case NameDesc = 'name_desc';
    case Company = 'company';
    case CompanyDesc = 'company_desc';
}
```

### 2. Add withSort() to CustomerQueryParams ✅
**File:** `app/Infrastructure/Shopwired/CustomerQueryParams.php`

```php
public function withSort(?CustomerSort $sort): self
{
    return new self(
        baseParams: $this->baseParams->withSort($sort?->value),
        trade: $this->trade,
        email: $this->email,
    );
}
```

### 3. Add Generator Method to ShopwiredPaginator
**File:** `app/Infrastructure/Shopwired/ShopwiredPaginator.php`

Add `pages()` generator method alongside existing `fetchAll()`:

```php
/**
 * Iterate pages from an endpoint (memory-efficient).
 *
 * @template T
 * @template P of PaginatableQueryParamsInterface
 *
 * @param P $params Initial query parameters
 * @param Closure(P): list<T> $fetchPage Callback to fetch one page
 * @param int|null $knownTotal Optional total count
 *
 * @return Generator<int, list<T>> Yields each page's items
 */
public static function pages(
    PaginatableQueryParamsInterface $params,
    Closure $fetchPage,
    ?int $knownTotal = null,
): Generator {
    // yields each page, doesn't accumulate
}
```

### 4. Update CustomerClientInterface
**File:** `app/Application/Contracts/Shopwired/CustomerClientInterface.php`

Add new method for batch iteration:

```php
/**
 * Iterate all customers in batches (memory-efficient).
 *
 * @return Generator<int, list<Customer>> Yields batches of ~100 customers
 */
public function iterateAllCustomerBatches(): Generator;
```

### 5. Update CustomerClient Implementation
**File:** `app/Infrastructure/Shopwired/Clients/CustomerClient.php`

Implement `iterateAllCustomerBatches()` using new paginator:

```php
public function iterateAllCustomerBatches(): Generator
{
    $params = CustomerQueryParams::forBulkFetch()
        ->withSort(CustomerSort::CreatedAsc)
        ->withBaseParams(
            ShopwiredQueryParams::forBulkFetch()
                ->withEmbeds(self::DEFAULT_EMBEDS)
                ->withFields(self::DEFAULT_FIELDS),
        );

    yield from ShopwiredPaginator::pages(
        params: $params,
        fetchPage: fn(CustomerQueryParams $p): array => $this->fetchCustomerPage($p),
        knownTotal: $this->getCustomerCount(),
    );
}
```

### 6. Create Migration
**File:** `database/migrations/xxxx_create_shopwired_customers_table.php`

```
shopwired_customers
├── id (bigint, PK)                    -- ShopWired ID
├── email (varchar, unique)
├── first_name (varchar)
├── last_name (varchar)
├── company_name (varchar, nullable)
├── trade (boolean)
├── trade_group_id (int, nullable)
├── active (boolean)
├── credit (boolean)                   -- credit account enabled
├── vat_number (varchar, nullable)
├── accepts_marketing (boolean)
├── shopwired_created_at (timestamp)   -- from API
├── synced_at (timestamp)              -- our tracking
```

**10 fields from API + 1 sync tracking.** Lean table - can expand via migration + re-sync if needed.

### 7. Create CustomerRepositoryInterface
**File:** `app/Application/Contracts/Shopwired/CustomerRepositoryInterface.php`

```php
interface CustomerRepositoryInterface
{
    /**
     * @param list<Customer> $customers
     */
    public function saveMany(array $customers): SaveResult;
}
```

### 8. Create CustomerRepository
**File:** `app/Infrastructure/Shopwired/Repositories/CustomerRepository.php`

With `saveMany()` using upsert for idempotency.

### 9. Create SyncCustomersUseCase
**File:** `app/Application/Shopwired/UseCases/SyncCustomersUseCase.php`

**Key difference from SyncOrdersUseCase**: Loops over batches instead of single array.

```php
public function execute(): SyncResult
{
    $totalFetched = 0;
    $totalSaved = 0;
    $totalFailed = 0;
    $failedReferences = [];

    foreach ($this->customerClient->iterateAllCustomerBatches() as $batch) {
        $totalFetched += count($batch);
        $saveResult = $this->repository->saveMany($batch);
        $totalSaved += $saveResult->succeeded;
        $totalFailed += $saveResult->failed;
        $failedReferences = [...$failedReferences, ...$saveResult->failedReferences];

        // Log progress every ~1000 customers (10 pages)
        if ($totalFetched % 1000 < 100) {
            $this->logger->info('Customer sync progress', ['fetched' => $totalFetched]);
        }
    }

    return new SyncResult($totalFetched, $totalSaved, $totalFailed, $failedReferences);
}
```

### 10. Create SyncShopwiredCustomersJob
**File:** `app/Presentation/Jobs/SyncShopwiredCustomersJob.php`

Follow `SyncShopwiredOrdersJob` pattern:
- 5 retries with exponential backoff
- Exception handling for permanent vs transient failures
- Static `weekly()` factory method

### 11. Add Weekly Schedule
**File:** `routes/console.php`

```php
Schedule::call(static fn() => SyncShopwiredCustomersJob::dispatch())
    ->name('sync-shopwired-customers-weekly')
    ->weeklyOn(Schedule::SUNDAY, '03:00')
    ->onOneServer()
    ->withoutOverlapping(30);
```

---

## Files to Modify/Create

| File | Action |
|------|--------|
| `app/Infrastructure/Shopwired/Enums/CustomerSort.php` | Create ✅ |
| `app/Infrastructure/Shopwired/CustomerQueryParams.php` | Modify ✅ |
| `app/Infrastructure/Shopwired/ShopwiredPaginator.php` | Modify (add `pages()`) |
| `app/Application/Contracts/Shopwired/CustomerClientInterface.php` | Modify (add `iterateAllCustomerBatches()`) |
| `app/Infrastructure/Shopwired/Clients/CustomerClient.php` | Modify (implement generator) |
| `database/migrations/xxxx_create_shopwired_customers_table.php` | Create |
| `app/Application/Contracts/Shopwired/CustomerRepositoryInterface.php` | Create |
| `app/Infrastructure/Shopwired/Repositories/CustomerRepository.php` | Create |
| `app/Application/Shopwired/UseCases/SyncCustomersUseCase.php` | Create |
| `app/Presentation/Jobs/SyncShopwiredCustomersJob.php` | Create |
| `routes/console.php` | Modify (add schedule) |

---

## Verification
1. Run `php artisan tinker` to test `CustomerSort` enum and sorting works
2. Dispatch job manually: `SyncShopwiredCustomersJob::dispatch()`
3. Monitor logs for batch progress (~600 batches over ~10 min)
4. Verify customer count in database matches API count (~67,710)
5. Verify memory usage stays constant (not growing with each batch)
