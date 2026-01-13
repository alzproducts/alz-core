# Plan: ShopWired Customer Sync Job

## Summary
Duplicate the `SyncShopwiredOrdersJob` pattern for customers with weekly full-sync strategy.

## Key Findings (from investigation)
- **60k customers / 100 per page = 600 API calls = ~10 minutes** at 60 req/min rate limit
- API supports sorting: `created`, `created_asc`, `created_desc`, `name`, `name_desc`, `company`, `company_desc`
- Sorting NOT implemented in our code yet (infrastructure exists in `ShopwiredQueryParams.withSort()`)
- Weekly full sync is simplest approach - catches new customers AND updates

## Implementation Steps

### 1. Add CustomerSort Enum
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

### 2. Add withSort() to CustomerQueryParams
**File:** `app/Infrastructure/Shopwired/CustomerQueryParams.php`

Add method to pass sort through to baseParams:
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

### 3. Update CustomerClient for sorted bulk fetch
**File:** `app/Infrastructure/Shopwired/Clients/CustomerClient.php`

Update `listAllCustomers()` to use `created_asc` sorting for deterministic ordering.

### 4. Create SyncCustomersUseCase
**File:** `app/Application/Shopwired/UseCases/SyncCustomersUseCase.php`

Follow `SyncOrdersUseCase` pattern:
- Fetch all customers via `CustomerClientInterface::listAllCustomers()`
- Persist via `CustomerRepositoryInterface::saveMany()`
- Return `SyncResult` with fetched/saved/failed counts

### 5. Create SyncShopwiredCustomersJob
**File:** `app/Presentation/Jobs/SyncShopwiredCustomersJob.php`

Follow `SyncShopwiredOrdersJob` pattern:
- 5 retries with exponential backoff
- Exception handling for permanent vs transient failures
- Static `weekly()` factory method

### 6. Add Weekly Schedule
**File:** `routes/console.php`

```php
Schedule::call(static fn() => SyncShopwiredCustomersJob::dispatch())
    ->name('sync-shopwired-customers-weekly')
    ->weeklyOn(Schedule::SUNDAY, '03:00')
    ->onOneServer()
    ->withoutOverlapping(30);
```

## Files to Modify/Create
| File | Action |
|------|--------|
| `app/Infrastructure/Shopwired/Enums/CustomerSort.php` | Create |
| `app/Infrastructure/Shopwired/CustomerQueryParams.php` | Modify (add withSort) |
| `app/Infrastructure/Shopwired/Clients/CustomerClient.php` | Modify (add sorting) |
| `app/Application/Shopwired/UseCases/SyncCustomersUseCase.php` | Create |
| `app/Presentation/Jobs/SyncShopwiredCustomersJob.php` | Create |
| `routes/console.php` | Modify (add schedule) |

## Additional Scope (from investigation)
**Neither `shopwired_customers` table nor `CustomerRepository` exist.** Need to create:

### 7. Create Migration
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

### 8. Create CustomerRepositoryInterface
**File:** `app/Application/Contracts/Shopwired/CustomerRepositoryInterface.php`

### 9. Create CustomerRepository
**File:** `app/Infrastructure/Shopwired/Repositories/CustomerRepository.php`

With `saveMany()` using upsert for idempotency.

## Verification
1. Run `php artisan tinker` to test `CustomerSort` enum and sorting works
2. Dispatch job manually: `SyncShopwiredCustomersJob::dispatch()`
3. Check logs for sync completion (~10 min)
4. Verify customer count in database matches API count (67,710)
