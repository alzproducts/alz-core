# Implementation Log: #467 — Add Open Orders to Linnworks Order Sync

## Issue Context

The Linnworks order sync was only handling ProcessedOrders. OpenOrders (pending/unpaid) were silently ignored, meaning active orders were never synced to the local database until they were processed. The `/v2/orders` API always returns both `OpenOrders` and `ProcessedOrders` arrays — we just needed to merge them.

Two main goals:
1. Cursor sync fetches both order types from the same API call
2. Hourly backup job syncs all open orders via SQL API + v2 REST

## Implementation

### Part 1 — Cursor Sync Handles Both Order Types

**`GetOrdersApiResponse`** — Added `$openOrders` property alongside `$processedOrders`. Both are `?array` with `#[DataCollectionOf(OrderResponse::class)]`.

**`OrderGeneralInfoResponse`** — Changed `isCancelled` from `bool` to `?bool` (absent on open orders).

**`OrderResponse::toDomain()`** — Changed `isCancelled: $this->generalInfo->isCancelled` to `isCancelled: $this->generalInfo->isCancelled ?? false`.

**`OrderClientInterface`** — Renamed:
- `iterateProcessedOrders()` → `iterateOrders()`
- `iterateProcessedOrdersByIds()` → `iterateOrdersByIds()`
- Updated docblocks to reflect both order types

**`OrderClient`** — Added private static `allOrderResponses()` helper that merges both arrays. Updated `iterateOrders()`, `iterateOrdersByIds()`, and `getOrderById()` to use this helper.

**`SyncLinnworksOrdersUseCase`** — Updated call from `iterateProcessedOrders` → `iterateOrders`.

**`BackfillLinnworksOrdersUseCase`** — Updated call from `iterateProcessedOrdersByIds` → `iterateOrdersByIds`.

### Part 2 — Hourly Open Orders Backup Sync

**`OpenOrderIdsQuery`** — New query in `app/Infrastructure/Linnworks/Queries/OpenOrderIdsQuery.php`. Co-located `OpenOrderIdsRow` DTO. SQL: `SELECT pkOrderID FROM [Open_Order]`.

**`OrderDashboardsClientInterface`** — Added `getOpenOrderIds(): array` method with full `@throws` declarations.

**`OrderDashboardsClient`** — Implemented `getOpenOrderIds()` delegating to `$this->dashboardsClient->execute(new OpenOrderIdsQuery())`.

**`SyncAllOpenLinnworksOrdersJob`** — New job in `app/Infrastructure/Jobs/Linnworks/`. Queue: `default`, timeout: 90s, tries: 4, uniqueFor: 120s. Follows `SyncHistoricalLinnworksOrdersJob` pattern.

**`LinnworksScheduleServiceProvider`** — Added `registerOpenOrdersSchedule()` called from `registerOrderSchedules()`. Runs hourly with 5-minute overlap protection.

### Tests Updated

- `SyncLinnworksOrdersUseCaseTest` — Updated all `iterateProcessedOrders` mock expectations → `iterateOrders`

### Tests Added

- `OpenOrderIdsQueryTest` — SQL generation + response mapping
- `SyncAllOpenLinnworksOrdersJobTest` — uniqueId, middleware, handle delegates to use case, handle skips when empty

## Test Results

2844 tests passed (6425 assertions) — all green. No failures introduced.

## Lint Results

All clean:
- Pint: pass
- PHPStan: no errors (0 violations)
- PHPArkitect: no violations
- Deptrac: 0 violations
- TLint: LGTM

## Handoff Notes

- All changes are backwards-compatible within the Clean Architecture layer boundaries
- The `ShouldBeUnique` + `Queue::fake()` concern (from tests/CLAUDE.md) was avoided by using Mockery partial mocks for the job tests
- Smoke test via tinker: `app(BackfillLinnworksOrdersUseCase::class)->execute(app(OrderDashboardsClientInterface::class)->getOpenOrderIds())`
