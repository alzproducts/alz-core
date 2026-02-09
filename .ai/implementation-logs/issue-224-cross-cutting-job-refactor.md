# Issue #224: Cross-Cutting Job Refactor

## Status: Complete

## Decision Log

| Decision | Choice | Rationale |
|----------|--------|-----------|
| After `$this->fail($e)` | Keep `throw $e` | Preserves `@throws`, conventional pattern |
| Logger in `handle()` | `LoggerInterface` via DI | Testable, PSR-3, matches Application layer rules |
| Logger in `failed()` | `Log` facade | No DI available in Laravel lifecycle hooks |
| Private helper methods | Pass `$logger` param | Consistent with handle()'s DI, avoids mixing facade+DI |
| Severity in failed() | `if/else` with `AbstractApiException` check | Known failures = error, unknown = critical |
| ProcessContactSubmissionJob failed() | Leave as-is | Delegates to HandleContactSubmissionFailureUseCase |
| PR strategy | Single PR | All changes are cross-cutting, same logical unit |
| `Log::{$level}()` pattern | Replace with `if/else` | PHPStan max level flags `staticMethod.dynamicName` |

## Deviations from Plan

1. **ProcessProductSearchFeedJob also had no constructor** - PHPStan rule fix caught this in addition to SyncCampaignLookupTableJob. Added constructor + ShouldBeUnique.
2. **Private helper methods in SetProductFreeDeliveryJob and CleanupStaleContactActionsJob** - Plan didn't mention passing `$logger` to private methods. Fixed to pass `$logger` through for consistency.
3. **Pint caught unused import in SyncProductRatingsJob** - `no_unused_imports` fixer removed leftover import from concurrent edits.
4. **`Log::{$level}()` replaced with `if/else`** - Plan used `$level` variable + dynamic call. PHPStan `staticMethod.dynamicName` rejects this at max level. Changed to explicit `if/else` calling `Log::error()` / `Log::critical()` directly.
5. **PHPStan rules needed `isEnum()` guard** - QueueName enum in `App\Application\Jobs\Enums\` triggered all 5 custom job rules. Added `$classReflection->isEnum()` early return to each.
6. **PHPArkitect needed Enums namespace exclusion** - Added `App\Application\Jobs\Enums` to the `NotResideInTheseNamespaces` exclusion list.
7. **SyncCampaignLookupTableJob moved to `low` queue** - Plan preserved existing `default` queue (from missing-constructor bug). Review found timeout=300s exceeds Horizon supervisor timeout for `default` queue (60-90s). Moved to `QueueName::Low` to match siblings and allow full timeout budget.

## Implementation Progress

- [x] Phase 0: Fix PHPStan `JobMustCallOnQueueRule` (`Rule<InClassNode>`)
- [x] Phase 1: Remove `SerializesModels`, redundant comments, fix missing constructors
- [x] Phase 2: Create `QueueName` enum, replace hardcoded strings
- [x] Phase 3: Consolidate logging (severity-aware `failed()`, remove catch-block logging)
- [x] Phase 4: Add `ShouldBeUnique` to 12 parameterless jobs
- [x] Phase 5: Inject `LoggerInterface` in `handle()` methods
- [x] Phase 6: Add `$maxExceptions` to 5 critical jobs
- [x] Phase 7: Trait/interface audit
- [x] Update 5 test files
- [x] Fix PHPStan `staticMethod.dynamicName` (20 jobs: `Log::{$level}` → `if/else`)
- [x] Fix PHPStan rules + PHPArkitect for QueueName enum
- [x] `make fix` + `make lint` + `make test` — all green

## PR Notes

### Summary
Cross-cutting improvements across all 21 queue jobs: severity-aware logging, `QueueName` enum, `ShouldBeUnique`, `LoggerInterface` injection, `$maxExceptions`, and a PHPStan rule fix that caught 2 jobs silently defaulting to wrong queues.

### Changes
- Fix `JobMustCallOnQueueRule` to use `Rule<InClassNode>` — catches jobs without constructors
- Move `SyncCampaignLookupTableJob` from `default` to `low` queue — timeout=300s exceeded Horizon supervisor timeout (60-90s)
- Add `isEnum()` guard to all 5 custom PHPStan job rules
- Remove `SerializesModels` from all 21 jobs (none use Eloquent model properties)
- Introduce `QueueName` enum replacing hardcoded queue strings
- Remove duplicate logging between catch blocks and `failed()` method
- Make `failed()` severity-aware: `AbstractApiException` → error, everything else → critical
- Add `ShouldBeUnique` to 12 parameterless jobs with appropriate `uniqueFor` values
- Inject `LoggerInterface` in `handle()` methods (Log facade retained in `failed()`)
- Add `$maxExceptions` to 5 critical eventual-consistency jobs
- Update 5 test files to match new logging patterns
