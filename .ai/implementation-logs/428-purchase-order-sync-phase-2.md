# Implementation Log: Purchase Order Sync Phase 2

**GitHub Issue**: #428
**Plan Document**: `.ai/plans/2026-03-31_428-linnworks-purchase-order-sync-phase-2.md`
**Status**: Complete
**Started**: 2026-03-31
**Completed**: 2026-03-31

## Overview

Wires the Phase 1 data layer (VOs, API clients, repository, DB tables) into a working sync system with three levels: fast (5 min, Core), normal (daily, Full), and full backfill (manual, Full).

## Branch
`feature/428-purchase-order-sync-phase-2-jobs`

## Decision Log

### 2026-03-31
- **Dispatcher interface added** (deviation from plan): Plan said "no dispatcher interface", but PHPArkitect/Deptrac enforce Presentation must not depend on Infrastructure. `BackfillPurchaseOrdersCommand` needed to dispatch jobs → created `PurchaseOrderBackfillDispatcherInterface` + `QueuedPurchaseOrderBackfillDispatcher`, following the existing `LinnworksBackfillDispatcherInterface` pattern.
- **`PurchaseOrderSyncTotalsResult` created**: Mutable accumulator (like `BackfillTotalsResult`) needed because per-PO saves don't use `saveMany()`. Solves PHPStan `int<0, max>` type constraint and keeps use case methods under 20-line limit.
- **`$failed` counter removed**: Simplify review identified `$failed` as redundant state — always equals `count($failedReferences)`. Derived via `count()` instead.
- **`BUFFER_SIZE` clarified**: Buffer controls progress log frequency and memory pressure, NOT I/O batching — each PO is saved individually. Added clarifying comments.
- **Schedule provider refactored**: `boot()` grew to 86 lines with new PO entries → extracted `registerStockSchedules()`, `registerOrderSchedules()`, `registerPurchaseOrderSchedules()`.

## Deviations from Plan

- **Added dispatcher interface**: Plan said "no dispatcher interface" but CA enforcement required it for Presentation→Infrastructure decoupling
- **Added `PurchaseOrderSyncTotalsResult`**: Not in plan; required by PHPStan (method length + type constraints)
- **Schedule provider refactored**: Plan only said "add 2 entries"; method length linter forced decomposition into sub-methods

## Files Created (14)

### Queries (3)
- [x] `app/Infrastructure/Linnworks/Queries/FastPurchaseOrderIdsQuery.php`
- [x] `app/Infrastructure/Linnworks/Queries/PurchaseOrderIdsByDateRangeQuery.php`
- [x] `app/Infrastructure/Linnworks/Queries/AllPurchaseOrderIdsQuery.php`

### Use Cases (2 + 1 accumulator)
- [x] `app/Application/Linnworks/UseCases/SyncPurchaseOrderCoreUseCase.php`
- [x] `app/Application/Linnworks/UseCases/SyncPurchaseOrderFullUseCase.php`
- [x] `app/Application/Linnworks/UseCases/PurchaseOrderSyncTotalsResult.php`

### Jobs (3)
- [x] `app/Infrastructure/Jobs/Linnworks/SyncFastPurchaseOrdersJob.php`
- [x] `app/Infrastructure/Jobs/Linnworks/SyncPurchaseOrdersByDateRangeJob.php`
- [x] `app/Infrastructure/Jobs/Linnworks/SyncAllPurchaseOrdersJob.php`

### Dispatcher (interface + implementation)
- [x] `app/Application/Contracts/Linnworks/PurchaseOrderBackfillDispatcherInterface.php`
- [x] `app/Infrastructure/Linnworks/Dispatchers/QueuedPurchaseOrderBackfillDispatcher.php`

### Console Command (1)
- [x] `app/Presentation/Console/Commands/BackfillPurchaseOrdersCommand.php`

## Files Modified (5)

- [x] `app/Application/Contracts/Linnworks/PurchaseDashboardsClientInterface.php` — +3 methods
- [x] `app/Infrastructure/Linnworks/Clients/PurchaseDashboardsClient.php` — +3 implementations
- [x] `app/Providers/Schedule/LinnworksScheduleServiceProvider.php` — refactored into sub-methods, +2 PO schedules
- [x] `app/Providers/LinnworksServiceProvider.php` — registered `PurchaseOrderBackfillDispatcherInterface`
- [x] `phpstan-complexity-baseline.neon` — removed stale boot() entry, updated register()/provides() counts

## Phase 2.5: Batch SQL Refactor (2026-03-31)

Fast sync was taking ~26s for 34 POs (1 REST call per PO). Redesigned to use batch SQL queries.

### Changes
- **Trimmed PurchaseOrderItem**: removed 15 fields duplicated from StockItem/Supplier syncs (sku, title, dimensions, barcode, supplier fields, etc.). 10 PO-native fields remain.
- **Restructured Core/Full boundary**: moved `additionalCosts`/`deliveredRecords` from `PurchaseOrderCore` → `PurchaseOrderFull`. Core now = header + items + noteCount (all SQL-fetchable).
- **New batch SQL queries**: `PurchaseOrderHeadersBatchQuery` (with subqueries for lineCount, deliveredLinesCount, noteCount) + `PurchaseOrderItemsBatchQuery`
- **Rewrote SyncPurchaseOrderCoreUseCase**: 2 SQL queries total instead of N REST calls. Assembly in PHP.
- **PurchaseOrderClient::getPurchaseOrderFull()**: now parses costs/deliveries from raw response (no longer on Core).
- **Migration**: drops 15 columns + sku index from `purchase_order_items`

### Decisions
- `lineCount`/`deliveredLinesCount`/`noteCount` computed via MS SQL subqueries (not PHP). `DeliveredLinesCount = COUNT WHERE Delivered = Quantity`.
- `isDeleted` dropped — orphan-delete in `syncItems()` handles removals.
- `Locked` field is string "True"/"False" in Linnworks SQL — converted to bool in Row DTO.
- `fkPurchasId` typo (missing 'e') in Linnworks DB — documented in query file.

### Files Created
- `app/Infrastructure/Linnworks/Queries/PurchaseOrderHeadersBatchQuery.php`
- `app/Infrastructure/Linnworks/Queries/PurchaseOrderItemsBatchQuery.php`
- `database/migrations/2026_03_31_100000_drop_redundant_columns_from_linnworks_purchase_order_items.php`

### Files Modified
- `app/Domain/Linnworks/ValueObjects/PurchaseOrderItem.php` — 15 fields removed
- `app/Domain/Linnworks/ValueObjects/PurchaseOrderCore.php` — costs/deliveries removed
- `app/Domain/Linnworks/ValueObjects/PurchaseOrderFull.php` — costs/deliveries added
- `app/Infrastructure/Linnworks/Responses/PurchaseOrder/PurchaseOrderItemResponse.php` — trimmed
- `app/Infrastructure/Linnworks/Responses/PurchaseOrder/PurchaseOrderCoreResponse.php` — costs/deliveries removed
- `app/Infrastructure/Linnworks/Models/PurchaseOrderItemModel.php` — trimmed
- `app/Infrastructure/Linnworks/Clients/PurchaseOrderClient.php` — restructured getPurchaseOrderFull()
- `app/Infrastructure/Linnworks/Repositories/EloquentPurchaseOrderSyncRepository.php` — save()/saveCore() updated
- `app/Application/Contracts/Linnworks/PurchaseDashboardsClientInterface.php` — +2 batch methods
- `app/Infrastructure/Linnworks/Clients/PurchaseDashboardsClient.php` — +2 batch implementations
- `app/Application/Linnworks/UseCases/SyncPurchaseOrderCoreUseCase.php` — rewritten for batch SQL

## Follow-up Candidates

- Unit tests for `SyncPurchaseOrderCoreUseCase` / `SyncPurchaseOrderFullUseCase` — buffer/flush loop with per-item error handling has meaningful branching logic
- Abstract base class for the two use cases — structurally near-identical; blocked by PHP generics limitation
- Shared `PurchaseOrderIdRow` across query classes — 4 identical Row DTOs; blocked by co-located-DTO pattern convention

## PR Notes

**feat(linnworks): Purchase Order sync Phase 2 — jobs, use cases, and orchestration (#428)**

Wires the Phase 1 data layer into a working sync system with three sync levels:
- **Fast sync** (every 5 min): Core data only, open/pending POs at OurWarehouse, 1 API call/PO
- **Normal sync** (daily): Full data, last 7 days by date range, 3 API calls/PO
- **Full backfill** (manual): All POs via artisan command or direct job dispatch

### Key Components
- 3 dashboard SQL queries (`FastPurchaseOrderIdsQuery`, `PurchaseOrderIdsByDateRangeQuery`, `AllPurchaseOrderIdsQuery`)
- 2 use cases with buffer/flush + continue-on-failure (`SyncPurchaseOrderCoreUseCase`, `SyncPurchaseOrderFullUseCase`)
- 3 jobs (`SyncFastPurchaseOrdersJob`, `SyncPurchaseOrdersByDateRangeJob`, `SyncAllPurchaseOrdersJob`)
- Schedule entries (fast sync every 5 min, normal sync daily)
- `BackfillPurchaseOrdersCommand` with `--all`, `--from`/`--to`, `--dry-run`, `--queue`

### Key Decisions
- Dispatcher interface added for CA compliance (plan said "no dispatcher" but Deptrac/PHPArkitect enforced it)
- `Schedule::call()` for daily date-range job (Octane safety — dates computed at execution time)
- `startOfMonth()->subMonths(6)` for fast sync window (avoids month-boundary gaps)
- Per-PO saves with continue-on-failure; `ExternalServiceUnavailableException` rethrows immediately
