# Implementation Log: Historical Linnworks Order Backfill

**GitHub Issue**: #394
**Plan Document**: .ai/plans/2026-03-29_394-sync-historical-linnworks-orders-sql-backfill.md
**Status**: In Progress (ready for commit)
**Started**: 2026-03-30
**Completed**: —

## Overview

Backfill ~110k historical Linnworks orders by querying order IDs via the Dashboards SQL API (no date limit), then fetching full orders via the v2 REST `id` parameter (bypasses ~30-day lookback). Two commands: safe date-range and full backfill.

## Decision Log

### 2026-03-30
- **Decision**: Used `Webmozart\Assert` instead of `InvalidArgumentException` for from/to validation in UseCase
- **Why**: PHPArkitect prohibits non-Domain exceptions in Application layer; Assert is an allowed Domain dependency

- **Decision**: Used `throw: false` on `->retry()` in LinnworksHttpTransport
- **Why**: Without it, Laravel's retry() throws its own exception after exhausting retries, bypassing the existing `executeWithAuthRetry()` flow and `LinnworksErrorHandler` translation

- **Decision**: UseCase accepts pre-fetched `list<Guid>` instead of fetching IDs internally
- **Why**: Eliminates double SQL API call — commands fetch IDs once for count display, then pass them to UseCase
- **Tradeoff**: UseCase is no longer self-contained (caller must provide IDs), but this is cleaner since commands need the count for dry-run/confirmation

- **Decision**: Used array-based `$totals` accumulator instead of individual variables
- **Why**: PHPStan enforces 20-line method limit and 4-parameter limit; individual variables require either long methods or methods with 5+ parameters. Array shape annotations provide equivalent type safety.

## Deviations from Plan

- **UseCase signature**: Plan had `execute(?from, ?to)` calling dashboards client internally. Changed to `execute(list<Guid> $orderIds)` to eliminate double SQL query (simplify review finding).
- **UseCase dependencies**: Removed `OrderDashboardsClientInterface` from UseCase constructor — commands own the SQL query lifecycle.
- **Method extraction**: PHPStan's 20-line method limit required aggressive extraction beyond what the plan described.

## Files Created/Modified

| Action | File | Layer |
|--------|------|-------|
| New | `Infrastructure/Linnworks/Queries/ProcessedOrderIdsQuery.php` | Infrastructure |
| New | `Application/Contracts/Linnworks/OrderDashboardsClientInterface.php` | Application |
| New | `Infrastructure/Linnworks/Clients/OrderDashboardsClient.php` | Infrastructure |
| New | `Application/Linnworks/UseCases/BackfillLinnworksOrdersUseCase.php` | Application |
| New | `Presentation/Console/Commands/BackfillLinnworksOrdersCommand.php` | Presentation |
| New | `Presentation/Console/Commands/BackfillAllLinnworksOrdersCommand.php` | Presentation |
| Modified | `Infrastructure/Linnworks/LinnworksHttpTransport.php` | Infrastructure |
| Modified | `Application/Contracts/Linnworks/OrderClientInterface.php` | Application |
| Modified | `Infrastructure/Linnworks/Clients/OrderClient.php` | Infrastructure |
| Modified | `Infrastructure/Linnworks/LinnworksClientFactory.php` | Infrastructure |
| Modified | `Providers/LinnworksServiceProvider.php` | Providers |
| Modified | `phpstan-complexity-baseline.neon` | Config |

## PR Notes

### What
Add SQL-based historical order backfill for Linnworks via two new artisan commands

### Why
v2 GetOrders API has ~30-day lookback limit on `fromDate`; ~110k historical orders need syncing. Workaround: SQL API retrieves all order IDs, REST API fetches full orders by ID (bypasses date filter).

### Key Decisions
- Two commands (safe date-range + full backfill with confirmation) sharing one UseCase
- Commands fetch IDs once, pass to UseCase (no double SQL query)
- Generator + chunking (200 IDs/chunk, 5 chunks/batch) for memory efficiency
- HTTP-level retry (429/5xx) added to all Linnworks API calls via transport layer

### Testing
- All 2766 existing tests pass (6232 assertions)
- All 5 linters pass clean (Pint, PHPStan, PHPArkitect, Deptrac, TLint)
