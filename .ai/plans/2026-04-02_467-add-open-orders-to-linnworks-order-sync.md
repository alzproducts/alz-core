# Plan: Add Open Orders to Linnworks Order Sync

## Context

Linnworks separates orders into OpenOrders (pending) and ProcessedOrders (shipped/completed). Our sync currently only handles ProcessedOrders. The experiment proved that open orders share the same API shape (only difference: `IsCancelled` missing on open orders) and save to the same local table without error. We need to:

1. Make the cursor sync fetch **both** order types from the same API call
2. Add an **hourly backup sync** that fetches all open order IDs via SQL and re-syncs them

---

## Part 1: Cursor Sync Handles Both Order Types

The `/v2/orders` endpoint always returns both `OpenOrders` and `ProcessedOrders` arrays. We just need to merge them.

### 1.1 — `GetOrdersApiResponse` (already done in experiment)
**File:** `app/Infrastructure/Linnworks/Responses/GetOrdersApiResponse.php`
- Keep both `$openOrders` and `$processedOrders` properties (already changed)

### 1.2 — `OrderGeneralInfoResponse` (already done in experiment)
**File:** `app/Infrastructure/Linnworks/Responses/OrderGeneralInfoResponse.php`
- Keep `isCancelled` as `?bool = null` (already changed)

### 1.3 — `OrderResponse::toDomain()` (already done in experiment)
**File:** `app/Infrastructure/Linnworks/Responses/OrderResponse.php`
- Keep `isCancelled ?? false` coalescing (already changed)

### 1.4 — `OrderClient` — merge both arrays + restore `includeProcessed`
**File:** `app/Infrastructure/Linnworks/Clients/OrderClient.php`

- Add private static helper to merge both response arrays:
  ```php
  private static function allOrderResponses(GetOrdersApiResponse $response): array
  {
      return [...($response->openOrders ?? []), ...($response->processedOrders ?? [])];
  }
  ```
- `fetchPage()` — add `'includeProcessed' => 'true'` back to query params
- `iterateProcessedOrders()` → **rename to `iterateOrders()`** — use `allOrderResponses()` helper
- `iterateProcessedOrdersByIds()` → **rename to `iterateOrdersByIds()`** — use `allOrderResponses()` helper
- `getOrderById()` — check both arrays via `allOrderResponses()` helper

### 1.5 — `OrderClientInterface` — rename methods
**File:** `app/Application/Contracts/Linnworks/OrderClientInterface.php`

- `iterateProcessedOrders()` → `iterateOrders()`
- `iterateProcessedOrdersByIds()` → `iterateOrdersByIds()`
- Update docblocks to reflect both order types

### 1.6 — Update callers of renamed methods
| File | Change |
|------|--------|
| `app/Application/Linnworks/UseCases/SyncLinnworksOrdersUseCase.php` | `iterateProcessedOrders` → `iterateOrders` |
| `app/Application/Linnworks/UseCases/BackfillLinnworksOrdersUseCase.php` | `iterateProcessedOrdersByIds` → `iterateOrdersByIds` |

### 1.7 — Update tests for renamed methods
| File | Change |
|------|--------|
| `tests/Unit/Application/Linnworks/UseCases/SyncLinnworksOrdersUseCaseTest.php` | Update mock method name |
| `tests/Unit/Application/Linnworks/UseCases/SyncLinnworksCursorUseCaseTest.php` | If it mocks OrderClient |
| `tests/Unit/Infrastructure/Jobs/Linnworks/SyncLinnworksOrdersJobTest.php` | If it references method names |
| `tests/Unit/Infrastructure/Jobs/Linnworks/SyncLinnworksOrdersByCursorJobTest.php` | If it references method names |

---

## Part 2: Hourly Open Orders Backup Sync

Pattern: SQL query → get all open order IDs → fetch full orders by ID → save. Reuses `BackfillLinnworksOrdersUseCase` (it just takes IDs and syncs them).

### 2.1 — `OpenOrderIdsQuery` (new file)
**File:** `app/Infrastructure/Linnworks/Queries/OpenOrderIdsQuery.php`

Following `ProcessedOrderIdsQuery` pattern (co-located Row DTO):
- Row DTO: reuse `ProcessedOrderIdsRow` or create `OpenOrderIdsRow` — same `pkOrderID` column mapping. Since `ProcessedOrderIdsRow` is marked `@internal`, create a shared `OrderIdRow` or just duplicate as `OpenOrderIdsRow`.
- SQL: `SELECT pkOrderID FROM [Open_Order]` — no date filters, no WHERE clause needed
- `mapResponse()`: maps rows to `list<Guid>` (same pattern)

### 2.2 — `OrderDashboardsClientInterface` — add method
**File:** `app/Application/Contracts/Linnworks/OrderDashboardsClientInterface.php`

```php
/** @return list<Guid> */
public function getOpenOrderIds(): array;
```

### 2.3 — `OrderDashboardsClient` — implement
**File:** `app/Infrastructure/Linnworks/Clients/OrderDashboardsClient.php`

```php
public function getOpenOrderIds(): array
{
    return $this->dashboardsClient->execute(new OpenOrderIdsQuery());
}
```

### 2.4 — `SyncAllOpenLinnworksOrdersJob` (new file)
**File:** `app/Infrastructure/Jobs/Linnworks/SyncAllOpenLinnworksOrdersJob.php`

Following `SyncHistoricalLinnworksOrdersJob` pattern but lightweight:
- Queue: `default` (open orders are important, not background work)
- Timeout: `90s` (only ~2 API calls for typical volume)
- Tries: `4`, backoff: `[30]` (matches cursor job pattern)
- UniqueFor: `120s`
- `ShouldBeUnique` (prevent overlapping runs)
- `handle()`:
  1. `$orderIds = $dashboardsClient->getOpenOrderIds()`
  2. `if ($orderIds !== []) { $useCase->execute($orderIds); }`

### 2.5 — Schedule the job
**File:** `app/Providers/Schedule/LinnworksScheduleServiceProvider.php`

Add to `registerOrderSchedules()` or a new `registerOpenOrderSchedules()` helper:
```php
Schedule::job(new SyncAllOpenLinnworksOrdersJob())
    ->name('sync-all-open-linnworks-orders')
    ->hourly()->onOneServer()->withoutOverlapping(5);
```

### 2.6 — Tests for new components
- `tests/Unit/Infrastructure/Linnworks/Queries/OpenOrderIdsQueryTest.php` — verify SQL output
- `tests/Unit/Infrastructure/Jobs/Linnworks/SyncAllOpenLinnworksOrdersJobTest.php` — verify job properties + handle flow

---

## Verification

1. `make fix` — auto-fix code style
2. `make lint` — PHPStan, Pint, PHPArkitect, Deptrac, TLint
3. `make test` — full test suite
4. Manual smoke test via tinker:
   ```php
   // Cursor sync (both types)
   app(SyncLinnworksOrdersUseCase::class)->execute(new DateTimeImmutable('-24 hours'));

   // Open orders backup (SQL → IDs → fetch)
   $ids = app(OrderDashboardsClientInterface::class)->getOpenOrderIds();
   app(BackfillLinnworksOrdersUseCase::class)->execute($ids);
   ```
5. Verify open orders have `processed = false` and processed orders have `processed = true` in the DB
