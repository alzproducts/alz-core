# Plan: Cursor-Based Incremental Sync for StockItemFull

## Context

The daily full sync (`SyncAllStockItemsUseCase`) fetches ~4k stock items from Linnworks and upserts them all. This works but means any item change can take up to 24 hours to appear locally. This feature adds a cursor-based incremental sync that detects recently-modified stock items via SQL query and dispatches individual sync jobs per item, providing near-real-time item data freshness.

## Architecture

```
Schedule (every 5 minutes)
  -> SyncStockItemsWithCursorJob (default queue, ShouldBeUnique)
    -> SyncStockItemWithCursorUseCase
      1. Read cursor (SyncCursorRepositoryInterface)
      2. Apply 7-day lookback cap via resolveSince()
      3. Query modified IDs via SQL — TOP 500, ordered by ModifiedDate ASC
      4. If count = 500 (hit cap):
         a. Dispatch SyncLinnworksStockItemsJob (full bulk sync)
         b. Advance cursor to NOW
         c. Return early
      5. If count < 500 (normal path):
         a. Dispatch SyncStockItemJob per Guid
         b. Advance cursor to last element's ModifiedDate
      6. Log summary

SyncStockItemJob (per-item, default queue, ShouldBeUnique per Guid)
  1. Fetch full item (InventoryClientInterface::getStockItemFull)
  2. Save to DB (StockItemRepositoryInterface::save)
```

**Overflow strategy**: If SQL returns exactly 500 rows (the TOP limit), it means there are likely MORE modified items. Instead of dispatching 500+ individual API calls, we trigger the existing bulk sync job which fetches ~200 items per page — far more efficient. The cursor advances to now so the next incremental run starts fresh.

Matches diagram: `docs/diagrams/SyncStockItemWithCursor.drawio`

## Implementation Steps

### 1. Add cursor type enum case
**Modify:** `app/Application/Enums/SyncCursorType.php`
- Add `LinnworksStockItemFull = 'linnworks_stock_item_full'`

### 2. Create SQL query object
**New:** `app/Infrastructure/Linnworks/Queries/ModifiedStockItemQuery.php`

Co-located `ModifiedStockItemRow` (@internal) + `ModifiedStockItemQuery`, following `DeltaStockLevelQuery.php` pattern:

**Row DTO** (co-located, @internal):
```php
final class ModifiedStockItemRow extends Data {
    public function __construct(
        #[MapInputName('pkStockItemID')]
        public readonly string $stockItemId,
        #[MapInputName('ModifiedDate')]
        public readonly string $modifiedDate,
    ) {}
}
```

**SQL**:
```sql
SELECT TOP 500 pkStockItemID, CAST(ModifiedDate AS DATETIME2(0)) AS 'ModifiedDate'
FROM [StockItem]
WHERE CAST(ModifiedDate AS DATETIME2(0)) > {$escapedDate}
ORDER BY ModifiedDate ASC
```

**`mapResponse()`** returns `list<ModifiedStockItemRow>` — use case extracts Guids and dates (same pattern as `DeltaStockLevelQuery` returning `list<StockLevelDeltaDTO>`).

- Date format: `$this->since->format('Y-m-d H:i:s.v')` via `SqlQueryBuilder::escapeString()`
- Constant `LIMIT = 500` on the query class for reuse
- Pattern: follow `DeltaStockLevelQuery.php` and `Queries/CLAUDE.md` template

### 3. Create Application DTO
**New:** `app/Application/Linnworks/DTOs/ModifiedStockItemDTO.php`
```php
final readonly class ModifiedStockItemDTO {
    public function __construct(
        public Guid $stockItemId,
        public DateTimeImmutable $modifiedDate,
    ) {}
}
```

Row's `toDomain()`:
```php
public function toDomain(): ModifiedStockItemDTO {
    return new ModifiedStockItemDTO(
        stockItemId: Guid::fromTrusted($this->stockItemId),
        modifiedDate: CarbonImmutable::parse($this->modifiedDate),
    );
}
```

Query's `mapResponse()` returns `list<ModifiedStockItemDTO>`.

### 4. Extend dashboards client interface + implementation
**Modify:** `app/Application/Contracts/Linnworks/StockDashboardsClientInterface.php`
- Add `getModifiedStockItemIdsSince(DateTimeImmutable $since): array` with `@return list<ModifiedStockItemDTO>` and standard `@throws` docblock

**Modify:** `app/Infrastructure/Linnworks/Clients/StockDashboardsClient.php`
- Implement: delegates to `$this->dashboardsClient->execute(new ModifiedStockItemQuery($since))`

### 5. Create per-item sync job
**New:** `app/Application/Jobs/Linnworks/SyncStockItemJob.php`
- Constructor: `Guid $stockItemId`, queue: `default`
- `ShouldBeUnique` with `uniqueId` = `'sync-stock-item-' . $stockItemId->value`, `uniqueFor = 300`
- `handle()`: call `getStockItemFull($this->stockItemId)` then `save()`
- Pattern A exception handling (TransientApiFailure, PermanentApiFailure, Throwable)
- `$tries = 3`, `$maxExceptions = 3`, `$backoff = [10, 30, 60]`, `$timeout = 30`

### 6. Create orchestration use case
**New:** `app/Application/Linnworks/UseCases/SyncStockItemWithCursorUseCase.php`

Dependencies:
- `StockDashboardsClientInterface` — query modified IDs
- `SyncCursorRepositoryInterface` — read/write cursor
- `LoggerInterface` — business event logging

Constants:
- `DEFAULT_LOOKBACK_HOURS = 24` — first run with no cursor
- `MAX_LOOKBACK_DAYS = 7` — safety cap
- `OVERFLOW_THRESHOLD = 500` — matches SQL TOP limit

Flow:
1. `getLastSyncDate(SyncCursorType::LinnworksStockItemFull)`
2. `resolveSince()` — cap to 7 days max (log warning if capped)
3. `getModifiedStockItemIdsSince($since)` → `list<ModifiedStockItemDTO>`
4. Early return if empty (log info)
5. **If count = OVERFLOW_THRESHOLD** (hit the SQL TOP limit):
   a. Log warning: "Modified items exceeded threshold, triggering full sync"
   b. `SyncLinnworksStockItemsJob::dispatch()` — trigger bulk sync
   c. Advance cursor to `new DateTimeImmutable('now')` — reset to current time
   d. Return early
6. **Normal path** (count < OVERFLOW_THRESHOLD):
   a. `SyncStockItemJob::dispatch($dto->stockItemId)` for each
   b. Advance cursor to last element's `modifiedDate` (ordered ASC, so last = newest)
7. Log summary (dispatched count, new cursor)

Note: Let exceptions bubble (no try-catch per Application layer rules).

### 7. Create scheduled orchestrator job
**New:** `app/Application/Jobs/Linnworks/SyncStockItemsWithCursorJob.php`
- `ShouldBeUnique`, queue: `default`
- `uniqueId` = `'sync-stock-items-with-cursor'`, `uniqueFor = 600`
- `$tries = 2`, `$maxExceptions = 2`, `$backoff = [30]`, `$timeout = 60`
- Pattern A exception handling
- Delegates to `SyncStockItemWithCursorUseCase::execute()`

### 8. Register schedule
**Modify:** `app/Providers/Schedule/LinnworksScheduleServiceProvider.php`
- Add `SyncStockItemsWithCursorJob` every 5 minutes
- `onOneServer()`, `withoutOverlapping(5)`

### 9. Increase low queue min processes
**Modify:** `config/horizon.php`
- Production `supervisor-low`: add `minProcesses => 2`
- Ensures low queue always has 2 workers available (currently auto-scales from 1)

## Reused Existing Code
| What | Where |
|------|-------|
| `getStockItemFull(Guid)` | `InventoryClient` line 275-289 (calls `fetchStockItemsFullByIds`) |
| `save(StockItemFull)` | `EloquentStockItemRepository` (upsert + relations in transaction) |
| `StockItemFullResponse` DTO | Handles both bulk and by-ID responses |
| `SyncCursorRepositoryInterface` | Cursor read/write via `EloquentSyncCursorRepository` |
| `AbstractLinnworksQuery` | Base class with READ UNCOMMITTED isolation |
| `SqlQueryBuilder::escapeString()` | Date escaping for SQL Server |
| `resolveSince()` pattern | From `SyncDeltaStockToShopwiredUseCase` line 149-167 |
| `SyncLinnworksStockItemsJob` | Dispatched on overflow (>= 500 modified items) |
| Pattern A job catches | From `SyncLinnworksStockItemsJob` line 96-123 |

## 7-Day Safety Guard
Implemented in PHP via `resolveSince()` (not SQL), matching the delta sync's lookback cap pattern. If cursor is older than 7 days, cap to 7 days ago and log warning.

## Overflow Strategy
SQL query uses `TOP 500`. If exactly 500 rows returned, there are likely more changes than can be efficiently handled per-item. Instead of making 500+ individual API calls, the use case dispatches the existing bulk sync job (`SyncLinnworksStockItemsJob`) which fetches ~200 items per API page — far more efficient. Cursor advances to `now` so next incremental run starts clean.

## Design Tradeoff
**Cursor advances before jobs complete**: Unlike the delta stock sync (inline work → advance cursor), this design dispatches jobs then advances. If individual jobs fail all retries, those items are missed until the daily full sync. Acceptable tradeoff for the fire-and-forget dispatch architecture. Document in use case docblock.

## Files Summary

| Action | File |
|--------|------|
| New | `app/Application/Linnworks/DTOs/ModifiedStockItemDTO.php` |
| New | `app/Infrastructure/Linnworks/Queries/ModifiedStockItemQuery.php` |
| New | `app/Application/Linnworks/UseCases/SyncStockItemWithCursorUseCase.php` |
| New | `app/Application/Jobs/Linnworks/SyncStockItemJob.php` |
| New | `app/Application/Jobs/Linnworks/SyncStockItemsWithCursorJob.php` |
| Modify | `app/Application/Enums/SyncCursorType.php` |
| Modify | `app/Application/Contracts/Linnworks/StockDashboardsClientInterface.php` |
| Modify | `app/Infrastructure/Linnworks/Clients/StockDashboardsClient.php` |
| Modify | `app/Providers/Schedule/LinnworksScheduleServiceProvider.php` |
| Modify | `config/horizon.php` (production supervisor-low minProcesses) |

## Verification
1. `make lint` — all linters pass (PHPStan, Pint, PHPArkitect, Deptrac)
2. `make test` — existing + new tests pass
3. Manual: dispatch `SyncStockItemsWithCursorJob` via tinker, verify cursor created, individual jobs dispatched
4. Check Horizon dashboard for job execution and completion
