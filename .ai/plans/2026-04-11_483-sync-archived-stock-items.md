# Issue #483 — Sync Archived & Logically-Deleted Stock Items

## Context

The `linnworks.stock_items` table only contains **active** stock items (~3,766 rows). Archived and logically-deleted items are never imported because the Linnworks Inventory API silently filters them out of every endpoint we use — `GetStockItemsFull`, `GetInventoryItemById`, and `GetStockItemsFullByIds` all return empty arrays when passed archived GUIDs (verified empirically in tinker).

This creates a visibility gap: at least **673 ShopWired SKUs have no matching Linnworks stock item** locally, and the `is_archived` / `is_logically_deleted` columns added in #475 are effectively no-ops because the `SyncArchivedStockItemFlagsJob` can only flag rows that already exist.

**The fix:** a new **weekly** sync that pulls archived and logically-deleted items directly from the Linnworks SQL Dashboards API (`Dashboards/ExecuteCustomScriptQuery`) — the only endpoint that returns raw table data regardless of archive state — and upserts them into `linnworks.stock_items` alongside active items. Archived items get stock levels of `0` (semantically correct — no live stock) and their `is_archived` / `is_logically_deleted` flags populated directly from the source row.

**Empirically confirmed in tinker:**
- `SELECT * FROM StockItem WHERE IsArchived=1 OR bLogicalDelete=1` returns **3,621 rows**
- **3,613 (99.8%)** have a populated `ItemNumber` (SKU); the remaining 8 are filtered out at the SQL layer (`ItemNumber IS NOT NULL AND ItemNumber <> ''`) — per user direction, empty-SKU rows must not be persisted
- Category metadata lives in the `ProductCategories` table (columns `CategoryId`, `CategoryName`) — joined via `LEFT JOIN [ProductCategories] c ON c.CategoryId = s.CategoryId`
- All intrinsic stock item fields are present in the raw SQL row (title, barcode, prices, tax, dimensions, weight, category ID, creation date, composite flag, inventory tracking type)
- Stock levels / extended properties / suppliers live in separate tables — deliberately skipped
- Per user direction: **ignore existing `SyncArchivedStockItemFlagsJob` / `syncArchivedFlags()` code** — this new sync runs in parallel without modifying them

## Design Summary

1. **New query** `ArchivedStockItemsFullQuery` under `app/Infrastructure/Linnworks/Queries/` — single SQL statement joining `StockItem ← ProductCategories` filtered by `(IsArchived=1 OR bLogicalDelete=1) AND ItemNumber IS NOT NULL AND ItemNumber <> ''`, plus co-located Row DTO that casts stringly-typed SQL values into a `StockItemFull` domain VO (with zero-filled stock levels).
2. **New application DTO** `ArchivedStockItemDTO` wrapping `StockItemFull` + two bool flags (`isArchived`, `isLogicallyDeleted`). Keeps flag state out of the shared domain VO to avoid a cascading blast radius through other sync paths.
3. **New interface method** `StockItemRepositoryInterface::upsertArchivedStockItems(list<ArchivedStockItemDTO>): SaveManyResult` with implementation calling `EloquentGateway::batchUpsertMany()` directly. Bypasses the existing `save()` override so archived items don't nuke historical extended properties / suppliers on items transitioning to archived.
4. **New use case** `SyncArchivedStockItemsUseCase` — thin: fetch via dashboards client, upsert via repository, log counts + memory (following the `gc_mem_caches()` pattern added in commit `0f2a355`).
5. **New job** `SyncArchivedStockItemsJob` mirroring `SyncArchivedStockItemFlagsJob`'s shape — `ServiceCircuitBreaker::linnworks()` middleware, `HandleApiExceptions`, low-priority queue.
6. **Schedule wiring** in `LinnworksScheduleServiceProvider` — weekly on Sunday at 02:00 UTC (`->weeklyOn(0, '02:00')`, offset from the daily midnight sync) via a new `registerArchivedItemsSchedule()` method adjacent to `registerArchivedFlagsSchedule()`.

## Why This Design

| Decision | Rationale |
|---|---|
| Use SQL Dashboards endpoint, not Inventory REST API | Inventory API silently filters archived items (proven empirically) |
| Construct `StockItemFull`, not the leaner `StockItem` | `StockItemRepositoryInterface extends RepositoryWriteInterface<StockItemFull>` and `StockItemModelMapper::toModelAttributes()` expects `StockItemFull`. Reuses the existing mapper. |
| New DTO wrapper instead of adding flags to `StockItemFull` | Zero blast radius. Adding to the VO would force `toModelAttributes()` updates, which would cascade into the daily active sync, cursor sync, and product enrichment paths. |
| New interface method instead of reusing `saveMany()` | The existing `save()` override performs `DELETE FROM stock_item_extended_properties/suppliers WHERE stock_item_id = ?` per row. For items transitioning to archived, that would destroy historical child data. A direct `batchUpsertMany()` only touches `stock_items`. |
| `batchUpsertMany()` at batch size 500 | ~8 batches for ~3,613 rows, handles timestamps/UUIDs/casts via `fillForInsert()` (confirmed in `EloquentGateway.php:350, 392, 498`) |
| Stock fields zero / JIT false | Archived items have no live stock by definition — zero is semantically correct, not a placeholder |
| Weekly schedule | Archived state changes slowly; daily is overkill. The existing hourly `SyncArchivedStockItemFlagsJob` already handles fast flag flips for rows we already have. |
| Run Sunday 02:00 UTC (`->weeklyOn(0, '02:00')`) | `->weekly()` defaults to **Sunday 00:00 UTC** which collides with the daily active sync (`->daily()` = 00:00 UTC). Offsetting by 2 hours avoids the midnight Linnworks API spike while still running during the lowest-traffic window. |

## Files to Create

```
app/Infrastructure/Linnworks/Queries/ArchivedStockItemsFullQuery.php
app/Application/Linnworks/DTOs/ArchivedStockItemDTO.php
app/Application/Linnworks/UseCases/SyncArchivedStockItemsUseCase.php
app/Infrastructure/Jobs/Linnworks/SyncArchivedStockItemsJob.php

tests/Unit/Infrastructure/Linnworks/Queries/ArchivedStockItemFullRowTest.php
tests/Unit/Application/Linnworks/UseCases/SyncArchivedStockItemsUseCaseTest.php
tests/Feature/Infrastructure/Linnworks/Repositories/EloquentStockItemRepositoryUpsertArchivedTest.php
```

## Files to Modify

```
app/Application/Contracts/Linnworks/StockItemRepositoryInterface.php    # Add upsertArchivedStockItems()
app/Infrastructure/Linnworks/Repositories/EloquentStockItemRepository.php # Implement upsertArchivedStockItems()
app/Application/Contracts/Linnworks/StockDashboardsClientInterface.php    # Add getArchivedStockItemsFull()
app/Infrastructure/Linnworks/Clients/StockDashboardsClient.php            # Implement getArchivedStockItemsFull()
app/Providers/Schedule/LinnworksScheduleServiceProvider.php               # Wire the weekly job
```

## Existing Code to Reuse

| What | Where | Purpose |
|---|---|---|
| `AbstractLinnworksQuery` | `app/Infrastructure/Linnworks/Queries/AbstractLinnworksQuery.php:26` | Base class for the new query — wraps SQL with read-uncommitted isolation |
| `DashboardsClient::execute()` | `app/Infrastructure/Linnworks/Clients/DashboardsClient.php` | Handles POST to `/api/Dashboards/ExecuteCustomScriptQuery` |
| `StockItemModelMapper::toModelAttributes()` | `app/Infrastructure/Linnworks/Mappers/StockItemModelMapper.php:72-98` | Converts `StockItemFull` to model attrs (spread into upsert rows) |
| `EloquentGateway::batchUpsertMany()` | `app/Infrastructure/Persistence/EloquentGateway.php:392` | Chunked upsert with timestamp/UUID handling via `fillForInsert()` |
| `LinnworksDateParser::parse()` | `app/Infrastructure/Linnworks/Support/LinnworksDateParser.php:24` | Parses `CreationDate` strings, handles sentinels, throws on malformed |
| `Guid::fromTrusted()` | `app/Domain/ValueObjects/Guid.php` | For `pkStockItemID` in Row DTO `toDomain()` |
| `ModifiedStockItemQuery` | `app/Infrastructure/Linnworks/Queries/ModifiedStockItemQuery.php` | Canonical co-located Row + Query template |
| `SyncArchivedStockItemFlagsJob` | `app/Infrastructure/Jobs/Linnworks/SyncArchivedStockItemFlagsJob.php` | Job shell to mirror (`$tries=4`, circuit breaker, low queue) |
| `ServiceCircuitBreaker::linnworks()` + `HandleApiExceptions` | (existing middleware) | Fail-fast on Linnworks outage, translate API exceptions |
| `SqlQueryBuilder` | `app/Infrastructure/Linnworks/Support/SqlQueryBuilder.php` | Not needed (query has no parameters), but available if escaping becomes necessary |

## Implementation Steps

### Step 1 — Create `ArchivedStockItemsFullQuery` + co-located Row DTO

> Schema confirmed by user: category metadata lives in `ProductCategories` (columns `CategoryId`, `CategoryName`), NOT the `Category` table. `StockItem` uses `pkStockItemID` as its PK. No tinker verification needed.


File: `app/Infrastructure/Linnworks/Queries/ArchivedStockItemsFullQuery.php`

Follow the canonical template from `ModifiedStockItemQuery.php`:

- `ArchivedStockItemFullRow extends Data` — all properties `readonly string` (matching Linnworks wire format); Spatie auto-casts numeric strings via `::from()` but the safest pattern (per `ArchivedStockItemsQuery.php`) is explicit `string` + manual cast in `toDomain()` for clarity
- `#[MapInputName(...)]` attributes for fields that differ from camelCase (`pkStockItemID`, `bContainsComposites`, `bLogicalDelete`, `IsArchived`, etc.)
- `toDomain(): StockItemFull` method performing the casts:
  - `Guid::fromTrusted($this->stockItemId)` → `stockItemId` (VO expects `string`, so `->value`)
  - `(float) $this->retailPrice`, `(float) $this->purchasePrice`
  - Tax rate: `((float) $this->taxRate) < 0 ? null : (float) $this->taxRate` (mirrors `StockItemFullResponse:84`)
  - `$this->isComposite === 'True'` → bool
  - `new Weight((float) $this->weight, WeightUnit::Kilogram)`
  - `new Dimensions((float) $this->dimHeight, (float) $this->dimWidth, (float) $this->dimDepth)`
  - `LinnworksDateParser::parse($this->creationDate)` → `?DateTimeImmutable`
  - `$this->categoryName ?? 'Default'` (LEFT JOIN may return null for orphaned category refs; matches the `category_name` column default in migration `2026_01_28_100000_add_category_to_linnworks_stock_items.php`)
  - Zero stock fields: `quantity: 0, available: 0, inOrder: 0, due: 0, minimumLevel: 0, jit: false`
  - `extendedProperties: []`, `suppliers: []`

- `ArchivedStockItemsFullQuery extends AbstractLinnworksQuery` with `buildQueryBody()`:

```sql
SELECT
    s.pkStockItemID,
    s.ItemNumber,
    s.ItemTitle,
    s.BarcodeNumber,
    s.PurchasePrice,
    s.RetailPrice,
    s.TaxRate,
    s.Weight,
    s.DimHeight,
    s.DimWidth,
    s.DimDepth,
    s.bContainsComposites,
    s.CategoryId,
    c.CategoryName,
    s.CreationDate,
    s.IsArchived,
    s.bLogicalDelete
FROM [StockItem] s
LEFT JOIN [ProductCategories] c ON c.CategoryId = s.CategoryId
WHERE (s.IsArchived = 1 OR s.bLogicalDelete = 1)
  AND s.ItemNumber IS NOT NULL
  AND s.ItemNumber <> ''
ORDER BY s.pkStockItemID
```

`ORDER BY` is not strictly required for upsert correctness but makes debugging / log-correlation deterministic and produces a stable batch boundary for `batchUpsertMany`.

- `mapResponse()` returns `list<ArchivedStockItemDTO>` — the Row DTO's `toDomain()` produces a `StockItemFull`, and `mapResponse` wraps each in an `ArchivedStockItemDTO` with the parsed flags.

### Step 2 — Create `ArchivedStockItemDTO`

File: `app/Application/Linnworks/DTOs/ArchivedStockItemDTO.php`

```php
final readonly class ArchivedStockItemDTO
{
    public function __construct(
        public StockItemFull $item,
        public bool $isArchived,
        public bool $isLogicallyDeleted,
    ) {}
}
```

Application-layer DTO (not Domain) — it's a transport shape specific to this sync, not a business concept.

### Step 3 — Extend `StockDashboardsClientInterface` + impl

Add:

```php
/**
 * @return list<ArchivedStockItemDTO>
 * @throws InvalidApiResponseException
 * @throws InvalidApiRequestException
 * @throws AuthenticationExpiredException
 * @throws ResourceNotFoundException
 * @throws ExternalServiceUnavailableException
 */
public function getArchivedStockItemsFull(): array;
```

Impl in `StockDashboardsClient.php` — one-liner mirroring `getArchivedStockItemIds()` at line 118-122:

```php
/** @var list<ArchivedStockItemDTO> */
return $this->dashboardsClient->execute(new ArchivedStockItemsFullQuery());
```

### Step 4 — Extend `StockItemRepositoryInterface` + impl

Add to interface:

```php
/**
 * Bulk upsert archived/deleted stock items by stock_item_id.
 *
 * Unlike save(), does NOT touch extended_properties or suppliers child tables —
 * preserves historical child data for items transitioning to archived state.
 *
 * @param list<ArchivedStockItemDTO> $records
 *
 * @throws ExternalServiceUnavailableException
 */
public function upsertArchivedStockItems(array $records): SaveManyResult;
```

Impl in `EloquentStockItemRepository.php`:

```php
public function upsertArchivedStockItems(array $records): SaveManyResult
{
    if ($records === []) {
        return new SaveManyResult(succeeded: 0, failed: 0, failedReferences: []);
    }

    $rows = \array_map(
        static fn(ArchivedStockItemDTO $r): array => [
            'stock_item_id' => $r->item->stockItemId,
            ...StockItemModelMapper::toModelAttributes($r->item),
            'is_archived' => $r->isArchived,
            'is_logically_deleted' => $r->isLogicallyDeleted,
        ],
        $records,
    );

    return $this->eloquentGateway->batchUpsertMany(
        modelClass: StockItemModel::class,
        rows: $rows,
        uniqueBy: ['stock_item_id'],
        batchSize: 500,
    );
}
```

### Step 5 — Create `SyncArchivedStockItemsUseCase`

File: `app/Application/Linnworks/UseCases/SyncArchivedStockItemsUseCase.php`

Thin orchestrator — mirrors `SyncArchivedStockItemFlagsUseCase` structure. Must declare `@throws InvalidApiResponseException` (raised by `LinnworksDateParser::parse` for malformed `CreationDate`) alongside the usual API/DB exception chain (`AuthenticationExpiredException`, `ExternalServiceUnavailableException`, `InvalidApiRequestException`, `ResourceNotFoundException`, `DatabaseOperationFailedException`, `DuplicateRecordException`):

```php
public function __construct(
    private StockDashboardsClientInterface $client,
    private StockItemRepositoryInterface $repository,
    private LoggerInterface $logger,
) {}

public function execute(): void
{
    $startedAt = microtime(true);
    $this->logger->info('Starting archived stock items sync');

    $records = $this->client->getArchivedStockItemsFull();

    if ($records === []) {
        $this->logger->info('No archived stock items found');
        return;
    }

    $result = $this->repository->upsertArchivedStockItems($records);

    \gc_collect_cycles();
    \gc_mem_caches();

    $this->logger->info('Completed archived stock items sync', [
        'total_fetched' => \count($records),
        'succeeded' => $result->succeeded,
        'failed' => $result->failed,
        'duration_seconds' => round(microtime(true) - $startedAt, 2),
        'memory_mb' => round(\memory_get_usage(false) / 1024 / 1024, 1),
        'peak_memory_mb' => round(\memory_get_peak_usage(false) / 1024 / 1024, 1),
    ]);
}
```

### Step 6 — Create `SyncArchivedStockItemsJob`

File: `app/Infrastructure/Jobs/Linnworks/SyncArchivedStockItemsJob.php`

Mirror `SyncArchivedStockItemFlagsJob`:

- `$tries = 3` (weekly job — fewer retries needed than hourly)
- `$backoff = [300, 900]` (5 min, 15 min)
- `$timeout = 900` (15 minutes — generous headroom for ~3.6k rows)
- `$uniqueFor = 86400` (24h — prevents concurrent weekly runs)
- `onQueue(QueueName::Low->value)` in constructor
- `middleware()` returns `[ServiceCircuitBreaker::linnworks(), new HandleApiExceptions()]`
- `handle(SyncArchivedStockItemsUseCase $useCase): void` — one-liner

### Step 7 — Schedule wiring

In `LinnworksScheduleServiceProvider::registerStockSchedules()`, add a call to the new private method `registerArchivedItemsSchedule()` adjacent to `registerArchivedFlagsSchedule()`:

```php
private function registerStockSchedules(): void
{
    // ... existing lines unchanged ...

    $this->registerArchivedFlagsSchedule();
    $this->registerArchivedItemsSchedule();  // NEW — sibling of flags schedule

    // EVERY 5 MIN: Cursor-based incremental stock item sync (unchanged)
    Schedule::job(new SyncStockItemsWithCursorJob())
        ->name('sync-stock-items-with-cursor')
        ->everyFiveMinutes()->onOneServer()->withoutOverlapping(5);
}
```

Then define the new method alongside `registerArchivedFlagsSchedule()`:

```php
private function registerArchivedItemsSchedule(): void
{
    // WEEKLY (Sunday 02:00 UTC): Full sync of archived/deleted stock items
    // via SQL Dashboards — these are filtered out of the Inventory REST API.
    // Offset from the daily sync at 00:00 UTC to avoid contention.
    Schedule::job(new SyncArchivedStockItemsJob())
        ->name('sync-archived-stock-items')
        ->weeklyOn(0, '02:00')->onOneServer()->withoutOverlapping(120);
}
```

## Tests

| Test | Scope | What it verifies |
|---|---|---|
| `ArchivedStockItemFullRowTest` | Unit | Row DTO casts `"True"`/`"False"` → bool, `"-1"` tax → null, string numerics → float, null `CategoryName` → `'Default'` (matching migration default), `CreationDate` sentinel → null, valid ISO datetime → `DateTimeImmutable`. Test with fixture rows matching real Linnworks response shape. |
| `SyncArchivedStockItemsUseCaseTest` | Unit | Mock client + repo. Verify: happy path calls `upsertArchivedStockItems` with the list; empty result short-circuits without repo call; logs contain `succeeded`/`failed` counts. |
| `EloquentStockItemRepositoryUpsertArchivedTest` | Feature | Real DB. Seed an active stock item with extended properties + suppliers. Call `upsertArchivedStockItems` with a record flipping it to archived. Assert: `is_archived=true`, `is_logically_deleted=true`, stock fields zeroed, **extended_properties and suppliers child rows still intact** (the critical bypass behavior). |

No new entries to `phpstan-complexity-baseline.neon` — all new classes will be under line limits (UseCase is ~40 lines, repository method ~25 lines, query ~60 lines inc. Row DTO).

## Verification (End-to-End)

1. **Tinker sanity** (run before wiring the schedule):
   ```bash
   php artisan tinker --execute="app(\App\Application\Linnworks\UseCases\SyncArchivedStockItemsUseCase::class)->execute();"
   ```
   Check `storage/logs/laravel.log` for the completion log line with counts (~3,613 total, ~3,613 succeeded, 0 failed).

2. **Verify the original issue test case** — SKU `100376` (mentioned in #483 as a known archived product):
   ```sql
   SELECT stock_item_id, item_number, is_archived, is_logically_deleted, item_title
   FROM linnworks.stock_items WHERE item_number = '100376';
   ```
   Expected: row exists, at least one flag is `true`.

3. **Verify the 673 ShopWired SKU gap** — re-run the cross-reference comparison mentioned in the issue and confirm the gap shrinks substantially (not necessarily to zero — some genuinely unlinked SKUs are expected).

4. **Verify child data preservation** — pick a previously-active item that's now archived. Before running, record any `stock_item_extended_properties` and `stock_item_suppliers` rows for its `stock_item_id`. After running, confirm those child rows are untouched (count unchanged, values unchanged).

5. **Lint + tests**:
   - `make lint` — zero new violations, no baseline additions
   - `make test` — all new tests pass, no regressions in existing stock sync tests

6. **Dispatch via queue** (final integration test):
   ```bash
   php artisan tinker --execute="\App\Infrastructure\Jobs\Linnworks\SyncArchivedStockItemsJob::dispatch();"
   ```
   Watch `storage/logs/laravel.log` — confirm queue worker picks it up, circuit breaker passes, use case completes, memory diagnostics are logged.

## Out of Scope (Explicit Non-Goals)

- Modifying/removing `SyncArchivedStockItemFlagsJob` or `syncArchivedFlags()` (per user direction — the two syncs coexist)
- Syncing stock levels for archived items (zeros are semantically correct)
- Syncing extended properties for archived items (preserved from prior active state)
- Syncing supplier relationships for archived items (preserved from prior active state)
- Backporting flags onto `StockItemFull` domain VO (intentionally avoided to contain blast radius)
- Changing the existing daily active sync, cursor sync, or product enrichment paths
