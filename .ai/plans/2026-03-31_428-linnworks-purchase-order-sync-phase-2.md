# Purchase Order Sync — Phase 2: Jobs, Use Cases & Orchestration

## Context

Phase 1 (PR #425) delivered the complete data layer: domain VOs (`PurchaseOrderCore`, `PurchaseOrderFull`), API client methods (`getPurchaseOrderCore`, `getPurchaseOrderFull`), repository (`save`, `saveCore`), dashboard SQL queries, 6 DB tables, and Eloquent models. Phase 2 wires it all together with orchestration — use cases that fetch and persist, jobs that schedule and dispatch, and queries that select the right POs for each sync level.

---

## Architecture Overview

```
Schedule / Command
        │
   ┌────┼────────────────────┐
   │    │                    │
   ▼    ▼                    ▼
Job 1  Job 2              Job 3
(Fast) (DateRange)        (Full)
   │    │                    │
   │    │   ┌────────────────┘
   │    │   │
   ▼    ▼   ▼
 Query  Query  Query          ← Dashboard SQL API (fetch IDs)
   │    │   │
   ▼    │   │
 Core   │   │
UseCase ▼   ▼
      Full UseCase            ← REST API (fetch PO data per ID)
   │    │   │
   ▼    ▼   ▼
saveCore() save()             ← Repository (persist)
```

---

## 3 Sync Levels

| Level | Name | PO Scope | Data Model | API Calls/PO | Frequency |
|-------|------|----------|------------|--------------|-----------|
| 1 | **Fast** | OPEN+PENDING+PARTIAL (6mo) + DELIVERED today, OurWarehouse | `PurchaseOrderCore` | 1 | Every 5 min |
| 2 | **Normal** | All statuses, DateOfDelivery OR DateOfPurchase in range | `PurchaseOrderFull` | 3 | Daily (last 7 days) |
| 3 | **Full** | All POs, no filters | `PurchaseOrderFull` | 3 | Manual / weekly |

---

## Step 1: Query Classes (3 new)

All extend `AbstractLinnworksQuery`, co-located Row DTO pattern per `Queries/CLAUDE.md`.

### 1a. `FastPurchaseOrderIdsQuery`

**File:** `app/Infrastructure/Linnworks/Queries/FastPurchaseOrderIdsQuery.php`

```php
final readonly class FastPurchaseOrderIdsQuery extends AbstractLinnworksQuery
{
    public function __construct(
        private DateTimeImmutable $createdSince,
        private bool $includeDeliveredToday = true,
    ) {}
}
```

**SQL:**
```sql
SELECT pkPurchaseID FROM [Purchase]
WHERE fkLocationId = '00000000-0000-0000-0000-000000000000'
AND (
  (Status IN ('OPEN', 'PENDING', 'PARTIAL') AND DateOfPurchase >= '{createdSince}')
  [OR (Status = 'DELIVERED' AND CAST(DateOfDelivery AS DATE) = CAST(GETDATE() AS DATE))]
)
ORDER BY DateOfPurchase ASC
```

The DELIVERED clause is conditional on `$includeDeliveredToday`.

### 1b. `PurchaseOrderIdsByDateRangeQuery`

**File:** `app/Infrastructure/Linnworks/Queries/PurchaseOrderIdsByDateRangeQuery.php`

```php
final readonly class PurchaseOrderIdsByDateRangeQuery extends AbstractLinnworksQuery
{
    public function __construct(
        private DateTimeImmutable $from,
        private DateTimeImmutable $to,
    ) {}
}
```

**SQL:**
```sql
SELECT pkPurchaseID FROM [Purchase]
WHERE (DateOfDelivery >= '{from}' AND DateOfDelivery < '{to}')
   OR (DateOfPurchase >= '{from}' AND DateOfPurchase < '{to}')
ORDER BY DateOfPurchase ASC
```

### 1c. `AllPurchaseOrderIdsQuery`

**File:** `app/Infrastructure/Linnworks/Queries/AllPurchaseOrderIdsQuery.php`

```php
final readonly class AllPurchaseOrderIdsQuery extends AbstractLinnworksQuery
{
    // No constructor params
}
```

**SQL:**
```sql
SELECT pkPurchaseID FROM [Purchase] ORDER BY DateOfPurchase ASC
```

All three define a co-located `PurchaseOrderIdRow` DTO (identical structure: single `pkPurchaseID` → `Guid` mapping). Each query file contains its own Row class per the co-located pattern in `Queries/CLAUDE.md`.

---

## Step 2: Dashboard Client Interface + Implementation

### Modify: `app/Application/Contracts/Linnworks/PurchaseDashboardsClientInterface.php`

Add 3 new methods:

```php
public function getFastSyncPurchaseOrderIds(
    DateTimeImmutable $createdSince,
    bool $includeDeliveredToday = true,
): array;

public function getPurchaseOrderIdsByDateRange(
    DateTimeImmutable $from,
    DateTimeImmutable $to,
): array;

public function getAllPurchaseOrderIds(): array;
```

### Modify: `app/Infrastructure/Linnworks/Clients/PurchaseDashboardsClient.php`

Implement the 3 methods, each constructing the appropriate query and executing via `DashboardsClient`.

---

## Step 3: Use Cases (2 new)

Both follow the `BackfillLinnworksOrdersUseCase` buffer/flush pattern — iterate IDs, fetch per-ID, buffer, flush in batches, continue-on-failure.

### 3a. `SyncPurchaseOrderCoreUseCase`

**File:** `app/Application/Linnworks/UseCases/SyncPurchaseOrderCoreUseCase.php`

```php
final readonly class SyncPurchaseOrderCoreUseCase
{
    private const int BUFFER_SIZE = 50;    // POs per batch (1 API call each, fast)
    private const int PROGRESS_LOG_INTERVAL = 5;

    public function __construct(
        private PurchaseOrderClientInterface $purchaseOrderClient,
        private PurchaseOrderSyncRepositoryInterface $repository,
        private LoggerInterface $logger,
    ) {}

    /** @param list<Guid> $purchaseOrderIds */
    public function execute(array $purchaseOrderIds): SyncResult
}
```

**Flow:**
1. Iterate `$purchaseOrderIds`
2. For each: `$core = $this->purchaseOrderClient->getPurchaseOrderCore($id)`
3. Buffer `$core` objects
4. When buffer reaches `BUFFER_SIZE`: flush via `flushCoreBuffer()` — iterates buffer calling `$repository->saveCore($core)` per item with per-item try/catch (catch `DatabaseOperationFailedException`/`DuplicateRecordException`, accumulate failures, continue). Mirrors `AbstractEloquentRepository::saveMany()` continue-on-failure logic. Transient `ExternalServiceUnavailableException` rethrown immediately.
5. Flush remaining buffer
6. Return `SyncResult`

**Note:** `saveCore()` has no batch equivalent (`saveMany()` only wraps `save()`). The continue-on-failure loop lives in the use case's `flushCoreBuffer()` private method.

### 3b. `SyncPurchaseOrderFullUseCase`

**File:** `app/Application/Linnworks/UseCases/SyncPurchaseOrderFullUseCase.php`

```php
final readonly class SyncPurchaseOrderFullUseCase
{
    private const int BUFFER_SIZE = 20;    // POs per batch (3 API calls each, slower)
    private const int PROGRESS_LOG_INTERVAL = 5;

    public function __construct(
        private PurchaseOrderClientInterface $purchaseOrderClient,
        private PurchaseOrderSyncRepositoryInterface $repository,
        private LoggerInterface $logger,
    ) {}

    /** @param list<Guid> $purchaseOrderIds */
    public function execute(array $purchaseOrderIds): SyncResult
}
```

Same pattern as Core, but:
- Uses `getPurchaseOrderFull($id)` (3 API calls)
- Flushes via `$repository->save($full)` (full orphan-delete)
- Smaller buffer (20 vs 50) because each PO requires 3x API calls

---

## Step 4: Jobs (3 new)

All in `app/Infrastructure/Jobs/Linnworks/`, follow existing job conventions.

### 4a. `SyncFastPurchaseOrdersJob`

**File:** `app/Infrastructure/Jobs/Linnworks/SyncFastPurchaseOrdersJob.php`

```php
final class SyncFastPurchaseOrdersJob implements ShouldBeUnique, ShouldQueue
{
    public int $tries = 4;
    public int $maxExceptions = 2;
    public bool $failOnTimeout = true;
    public array $backoff = [30, 120];
    public int $timeout = 300;        // 5 minutes
    public int $uniqueFor = 600;      // 10 minutes

    public function __construct()
    {
        $this->onQueue(QueueName::Default->value);
    }

    public function uniqueId(): string { return 'sync-fast-purchase-orders'; }
    public function retryUntil(): DateTimeImmutable { return now()->addHours(1)->toDateTimeImmutable(); }
    public function middleware(): array { return [ServiceCircuitBreaker::linnworks(), new HandleApiExceptions()]; }

    public function handle(
        SyncPurchaseOrderCoreUseCase $useCase,
        PurchaseDashboardsClientInterface $dashboardsClient,
    ): void {
        // Calculated in handle() for Octane safety
        $createdSince = now()->subMonths(6)->startOfMonth()->toDateTimeImmutable();

        $ids = $dashboardsClient->getFastSyncPurchaseOrderIds(
            createdSince: $createdSince,
            includeDeliveredToday: true,
        );

        if ($ids !== []) {
            $useCase->execute($ids);
        }
    }
}
```

Note: `startOfMonth()->subMonths(6)` follows the critical pitfall documented in CLAUDE.md — avoids month-boundary gaps.

### 4b. `SyncPurchaseOrdersByDateRangeJob`

**File:** `app/Infrastructure/Jobs/Linnworks/SyncPurchaseOrdersByDateRangeJob.php`

```php
final class SyncPurchaseOrdersByDateRangeJob implements ShouldBeUnique, ShouldQueue
{
    public int $tries = 3;
    public int $maxExceptions = 2;
    public bool $failOnTimeout = true;
    public array $backoff = [60, 300];
    public int $timeout = 3600;       // 1 hour
    public int $uniqueFor = 7200;

    public function __construct(
        public readonly DateTimeImmutable $from,
        public readonly DateTimeImmutable $to,
    ) {
        $this->onQueue(QueueName::Low->value);
    }

    public function uniqueId(): string
    {
        return 'sync-purchase-orders-date-range-'
            . $this->from->format('Y-m-d') . '-'
            . $this->to->format('Y-m-d');
    }

    public function retryUntil(): DateTimeImmutable { return now()->addHours(6)->toDateTimeImmutable(); }
    public function middleware(): array { return [ServiceCircuitBreaker::linnworks(), new HandleApiExceptions()]; }

    public function handle(
        SyncPurchaseOrderFullUseCase $useCase,
        PurchaseDashboardsClientInterface $dashboardsClient,
    ): void {
        $ids = $dashboardsClient->getPurchaseOrderIdsByDateRange($this->from, $this->to);

        if ($ids !== []) {
            $useCase->execute($ids);
        }
    }
}
```

### 4c. `SyncAllPurchaseOrdersJob`

**File:** `app/Infrastructure/Jobs/Linnworks/SyncAllPurchaseOrdersJob.php`

```php
final class SyncAllPurchaseOrdersJob implements ShouldBeUnique, ShouldQueue
{
    public int $tries = 3;
    public int $maxExceptions = 2;
    public bool $failOnTimeout = true;
    public array $backoff = [120, 600];
    public int $timeout = 14400;      // 4 hours (every PO × 3 API calls)
    public int $uniqueFor = 18000;

    public function __construct()
    {
        $this->onQueue(QueueName::Low->value);
    }

    public function uniqueId(): string { return 'sync-all-purchase-orders'; }
    public function retryUntil(): DateTimeImmutable { return now()->addHours(24)->toDateTimeImmutable(); }
    public function middleware(): array { return [ServiceCircuitBreaker::linnworks(), new HandleApiExceptions()]; }

    public function handle(
        SyncPurchaseOrderFullUseCase $useCase,
        PurchaseDashboardsClientInterface $dashboardsClient,
    ): void {
        $ids = $dashboardsClient->getAllPurchaseOrderIds();

        if ($ids !== []) {
            $useCase->execute($ids);
        }
    }
}
```

---

## Step 5: Schedule Registration

### Modify: `app/Providers/Schedule/LinnworksScheduleServiceProvider.php`

```php
// Fast PO sync — every 5 minutes (Core: 1 API call per PO)
Schedule::job(new SyncFastPurchaseOrdersJob())
    ->name('sync-fast-purchase-orders')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(5);

// Normal PO sync — daily, last 7 days (Full: 3 API calls per PO)
// Uses Schedule::call() to calculate dates at execution time (Octane-safe)
Schedule::call(static function (): void {
    SyncPurchaseOrdersByDateRangeJob::dispatch(
        now()->subDays(7)->startOfDay()->toDateTimeImmutable(),
        now()->toDateTimeImmutable(),
    );
})
    ->name('sync-purchase-orders-daily')
    ->daily()
    ->onOneServer()
    ->withoutOverlapping(30);
```

---

## Step 6: Console Command

### New: `app/Presentation/Console/Commands/BackfillPurchaseOrdersCommand.php`

```
php artisan linnworks:backfill-purchase-orders [--from=] [--to=] [--all] [--queue]
```

- `--all`: Full backfill (dispatches `SyncAllPurchaseOrdersJob`)
- `--from`/`--to`: Date range (dispatches `SyncPurchaseOrdersByDateRangeJob`)
- No flags: Interactive prompts
- `--queue`: Dispatch to background job vs inline execution

---

## Files Summary

### New (9 files)
| File | Type |
|------|------|
| `app/Infrastructure/Linnworks/Queries/FastPurchaseOrderIdsQuery.php` | Query |
| `app/Infrastructure/Linnworks/Queries/PurchaseOrderIdsByDateRangeQuery.php` | Query |
| `app/Infrastructure/Linnworks/Queries/AllPurchaseOrderIdsQuery.php` | Query |
| `app/Application/Linnworks/UseCases/SyncPurchaseOrderCoreUseCase.php` | Use Case |
| `app/Application/Linnworks/UseCases/SyncPurchaseOrderFullUseCase.php` | Use Case |
| `app/Infrastructure/Jobs/Linnworks/SyncFastPurchaseOrdersJob.php` | Job |
| `app/Infrastructure/Jobs/Linnworks/SyncPurchaseOrdersByDateRangeJob.php` | Job |
| `app/Infrastructure/Jobs/Linnworks/SyncAllPurchaseOrdersJob.php` | Job |
| `app/Presentation/Console/Commands/BackfillPurchaseOrdersCommand.php` | Command |

### Modified (3 files)
| File | Change |
|------|--------|
| `app/Application/Contracts/Linnworks/PurchaseDashboardsClientInterface.php` | +3 methods |
| `app/Infrastructure/Linnworks/Clients/PurchaseDashboardsClient.php` | +3 method implementations |
| `app/Providers/Schedule/LinnworksScheduleServiceProvider.php` | +2 schedule entries |

---

## Verification

1. Run queries via tinker against real Linnworks API to confirm SQL correctness
2. Run fast sync manually: `SyncFastPurchaseOrdersJob::dispatchSync()`
3. Run date range sync: `SyncPurchaseOrdersByDateRangeJob::dispatchSync(from, to)`
4. Verify POs appear in `linnworks.purchase_orders` + child tables
5. `make lint` + `make test` pass

---

## Decisions

1. **No dispatcher interface** — jobs + schedule + console command only. No Application-layer programmatic dispatch needed.
2. **Console command included** — `BackfillPurchaseOrdersCommand` with `--all`, `--from`/`--to`, `--queue` flags.
3. **Normal sync daily range**: Last 7 days — narrower window, relies on fast sync for recent POs.
