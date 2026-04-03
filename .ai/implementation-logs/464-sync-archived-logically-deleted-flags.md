# Implementation Log: #464 — Sync is_archived and is_logically_deleted flags from Linnworks stock items

## Issue Context
`IsArchived` and `bLogicalDelete` are needed to filter stock items in reorder reports.
These flags exist in the Linnworks database but are not returned by the REST API.
They are only accessible via the `ExecuteCustomScriptQuery` SQL endpoint.

## Implementation

### Sub-task 1: Migration
- `database/migrations/2026_04_03_100000_add_archived_flags_to_linnworks_stock_items.php`
- Added `is_archived` (boolean, default false) and `is_logically_deleted` (boolean, default false) columns
- Named per schema convention (includes `linnworks` in filename)

### Sub-task 2: Query class
- `app/Infrastructure/Linnworks/Queries/ArchivedStockItemsQuery.php`
- Co-located Row DTO pattern: `ArchivedStockItemRow` + `ArchivedStockItemsQuery` in same file
- SQL fetches only flagged rows (`IsArchived = 1 OR bLogicalDelete = 1`)
- `mapResponse()` partitions results into separate `archivedIds` / `deletedIds` lists

### Sub-task 3: Application DTO
- `app/Application/Linnworks/DTOs/ArchivedStockItemFlagsDTO.php`
- Plain `final readonly class` (not Spatie Data — Application layer)
- Holds `list<string> $archivedIds` and `list<string> $deletedIds`

### Sub-task 4: Interface updates
- `app/Application/Contracts/Linnworks/StockDashboardsClientInterface.php` — added `getArchivedStockItemIds()`
- `app/Application/Contracts/Linnworks/StockItemRepositoryInterface.php` — added `syncArchivedFlags()`

### Sub-task 5: Client implementation
- `app/Infrastructure/Linnworks/Clients/StockDashboardsClient.php` — added `getArchivedStockItemIds()`

### Sub-task 6: Repository implementation
- `app/Infrastructure/Linnworks/Repositories/EloquentStockItemRepository.php` — added `syncArchivedFlags()`
- Two-pass bulk update per flag: `whereIn → set true`, `whereNotIn + where(flag=true) → set false`
- Wrapped in `transact()` for atomicity

### Sub-task 7: UseCase
- `app/Application/Linnworks/UseCases/SyncArchivedStockItemFlagsUseCase.php`
- Thin orchestrator: fetch flags → sync to repo

### Sub-task 8: Job
- `app/Infrastructure/Jobs/Linnworks/SyncArchivedStockItemFlagsJob.php`
- `ShouldBeUnique`, `hourly` execution

### Sub-task 9: Model update
- `app/Infrastructure/Linnworks/Models/StockItemModel.php`
- Added `is_archived` and `is_logically_deleted` to docblock and `casts()`

### Sub-task 10: Schedule registration
- `app/Providers/Schedule/LinnworksScheduleServiceProvider.php`
- Added hourly `SyncArchivedStockItemFlagsJob` to `registerStockSchedules()`

## Test Results

- `make test-quick` (domain suite): 1411 passed, 0 failures

## Lint Results

**Fixed:**
- Extracted `booleanCasts()` from `StockItemModel::casts()` (exceeded 20-line limit after adding 2 new casts)
- Extracted `partitionRows()` from `ArchivedStockItemsQuery::mapResponse()` (then trimmed blank lines inside foreach to meet the limit)
- Extracted `applyFlagSync()` from `EloquentStockItemRepository::syncArchivedFlags()` (deduplicates the two-pass pattern for both flags)
- Extracted `registerArchivedFlagsSchedule()` from `LinnworksScheduleServiceProvider::registerStockSchedules()`
- Added `@throws DatabaseOperationFailedException|DuplicateRecordException` to `SyncArchivedStockItemFlagsJob::handle()`
- Pint `static_lambda` fixer applied to repository closure

**Final:** All 5 linters pass — Pint, PHPStan, PHPArkitect, Deptrac, TLint.

## Handoff Notes

- The `applyFlagSync(string $column, array $flaggedIds)` private method in the repository is a neat DRY abstraction — identical two-pass pattern applies for both boolean flags
- The `whereNotIn('stock_item_id', [])` edge case is safe in Laravel: when `$flaggedIds === []`, `whereNotIn` with an empty array selects ALL rows, so the reset will clear all previously-flagged rows (correct behavior when Linnworks has no archived items)
- `ArchivedStockItemRow` maps `IsArchived`/`bLogicalDelete` as `int` (not `bool`) to avoid Spatie coercion issues with Linnworks SQL bit fields
- No existing tests broke — the only change to existing files was additive (new columns in model, new interface methods, new schedule entry)
