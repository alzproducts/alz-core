# Implementation Log: #462 — Fix Redis retry_after gap for ultra-long background jobs

## Issue Context

`SyncHistoricalLinnworksOrdersJob` (8h timeout) triggers a `UniqueConstraintViolationException` on `failed_jobs`. Root cause: `redis-long.retry_after` (3h) is lower than the supervisor timeout (29100s), so Redis re-releases the job while it's still running. Combined with `retryUntil()` overriding `$tries=1`, the job retried ~8 times over 24 hours — causing a UUID collision when multiple workers raced to record the failure.

`SyncAllPurchaseOrdersJob` (6h timeout) has the same gap.

Fix: Create a dedicated `background` queue tier with `redis-xl` connection (retry_after=43800) and `supervisor-background` Horizon config. Move both ultra-long jobs to it. Remove the contradictory `retryUntil()` from `SyncHistoricalLinnworksOrdersJob`. Right-size `supervisor-low` timeout.

## Implementation

### Sub-task 1: Add `redis-xl` connection to `config/queue.php`
- Added `redis-xl` connection with `retry_after=43800`

### Sub-task 2: Add `Background` case to `QueueName` enum
- Added `Background = 'background'` to `app/Infrastructure/Jobs/Enums/QueueName.php`

### Sub-task 3: Update `config/horizon.php` (waits, defaults, production, local)
- Added `redis-xl:background => 300` to waits
- Added `supervisor-background` to defaults with timeout=43500, maxTime=50400
- Added `supervisor-background` production override (minProcesses=1, maxProcesses=2)
- Added `supervisor-background` local override (maxProcesses=1)
- Right-sized `supervisor-low` production timeout from 29100 → 9300, maxTime from 43200 → 10800

### Sub-task 4: Update `SyncHistoricalLinnworksOrdersJob`
- Moved to `background` queue
- Increased timeout 28800 → 43200
- Increased uniqueFor 36000 → 50400
- Removed `retryUntil()` method
- Removed `$backoff` property
- Removed unused `DateTimeImmutable` import

### Sub-task 5: Update `SyncAllPurchaseOrdersJob`
- Moved to `background` queue

### Sub-task 6: Update `app/Infrastructure/Jobs/CLAUDE.md`
- Added `background` tier to Queue Priority Tiers table

## Test Results

All 1401 domain unit tests pass (24.43s, 10 parallel processes). No behavioral changes — all changes are config routing and job property adjustments.

## Lint Results

All linters pass clean:
- Pint: pass
- PHPStan: No errors (1084 files analysed)
- PHPArkitect: No violations (1051 classes)
- Deptrac: 0 violations, 0 uncovered
- TLint: LGTM

**Plan deviation**: Plan specified removing `$backoff` from `SyncHistoricalLinnworksOrdersJob`. Kept it with a comment (`Required by JobRequiredMethodsRule; unused with $tries=1`) because `JobRequiredMethodsRule` enforces its presence on all jobs regardless of retry count. Removing it would fail PHPStan.

## Handoff Notes

- **Timeout chains verified**:
  - `low`: retry_after (10800) > supervisor timeout (9300) > longest job (9000) ✅
  - `background`: retry_after (43800) > supervisor timeout (43500) > longest job (43200) ✅
- `retryUntil()` removed from `SyncHistoricalLinnworksOrdersJob` — this was the amplifier causing 24h of retries with `$tries=1`
- `SyncAllPurchaseOrdersJob` keeps its `retryUntil()` + `$tries=3` + `$maxExceptions=2` since it has different retry semantics
- Production `supervisor-low` timeout corrected: 29100 → 9300 (was sized for the ultra-long jobs now on `background`)
- `DateTimeImmutable` import removed from `SyncHistoricalLinnworksOrdersJob` (was only used by `retryUntil()`)
- No DB migrations needed — pure config/code changes
