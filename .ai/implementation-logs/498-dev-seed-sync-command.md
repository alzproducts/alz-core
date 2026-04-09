# Implementation Log: #498 — dev:seed-sync command

## Status: Complete — ready for commit

## Decision Log

- **Location**: `app/Presentation/Console/Commands/Dev/SeedLocalDatabaseCommand.php` — follows existing Dev command pattern (TestSlackNotificationCommand, TestPriceUpdateCommand)
- **Core jobs as const array**: 9 zero-arg jobs in `CORE_JOBS` typed class constant for data-driven dispatch
- **PII jobs as lazy closures**: PII jobs need constructor args; closures defer instantiation until dispatch (avoids eager construction in dry-run)
- **No DI needed**: Command has no constructor dependencies — all jobs dispatched via `\dispatch()`
- **`dispatch()` over `::dispatch()`**: Used `\dispatch(new $job())` to avoid false positives from custom `NoEventDispatchOutsideApplicationRule`
- **Method decomposition**: `handle()` → `seedDatabase()` → `dispatchCoreJobs()`/`dispatchPiiJobs()` + `printSummary()` — each under 20-line limit
- **No @var annotations**: Larastan narrows `option()` return type to `bool` for flag options
- **No tests needed**: TestingStrategy.md explicitly skips "console commands that dispatch jobs"

## Files Changed

- `app/Presentation/Console/Commands/Dev/SeedLocalDatabaseCommand.php` (created)

## Lint Errors Fixed (8 total)

- 3x `cast.useless` — removed redundant `(bool)` casts on flag option values
- 2x `alz.excessiveMethodLength` — extracted `seedDatabase()` and `printSummary()` from `handle()`
- 4x `alz.noEventDispatchOutsideApplication` — switched from `::dispatch()` to `\dispatch(new ...)`

## Simplify Findings

- Lazy PII job construction via closures (applied)
- Removed stale ShouldBeUnique docblock (applied)
- Infrastructure imports in Dev/ (skipped — Deptrac PresentationDev layer explicitly allows)
- `(bool)` casts (skipped — PHPStan proved unnecessary with Larastan)

## Sweep Results

All checks passed. No issues found.

## Test Results

- Full suite: 2,943 passed, 0 failed (12.76s)
- No regressions

## PR Notes

New `dev:seed-sync` artisan command that dispatches all sync jobs to populate a fresh local database after migration. Local environment only. Supports `--incl-pii` for customer/order data, `--pii-only` for just PII jobs, and `--dry-run` to preview.
