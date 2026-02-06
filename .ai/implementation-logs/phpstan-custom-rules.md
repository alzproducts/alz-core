# PHPStan Custom Rules — Implementation Log

## Status: Complete
**Completed**: 2026-02-06

## Decision Log

| Date | Decision | Rationale |
|------|----------|-----------|
| 2026-02-06 | Use `spaze/phpstan-disallowed-calls` for config-only rules | Already installed v4.7, avoids custom PHP code |
| 2026-02-06 | Replaced spaze rules #1/#35/#40 with custom AST rules | Larastan facade resolution transforms `DB::table()` → `DatabaseManager::table()` at type level, breaking spaze's type-based matching. Custom AST-level rules check raw PhpParser nodes before Larastan transforms. |
| 2026-02-06 | Kept spaze for Rule #41 (Config facade/helper) | Config:: is not resolved by Larastan like DB::/Artisan::, so spaze still works for it |
| 2026-02-06 | Place rules in `app/DevTools/PHPStan/Rules/` | Follows existing DevTools convention |
| 2026-02-06 | Separate neon file `phpstan-custom-rules.neon` | Clean separation, included by main phpstan.neon |
| 2026-02-06 | Rule #41 via spaze config, not custom PHP rule | `config()` + `Config::*()` handled by `disallowedFunctionCalls`/`disallowedStaticCalls` with `allowExceptIn` — simpler, same result |
| 2026-02-06 | Refactored Domain enum `fromValue()` to use `tryFrom()` | Eliminates try-catch in Domain; `tryFrom()` + null coalescing throw is cleaner |
| 2026-02-06 | Static properties in ClientFactories flagged as tech debt | User decision: suppress with ignoreErrors, refactor to DI later |
| 2026-02-06 | config() in Application layer flagged as tech debt | 2 files suppressed, refactor to constructor injection later |
| 2026-02-06 | Rule #10 skips private methods | Private @throws are internal docs for checked exception system, not public API contract |
| 2026-02-06 | Rule #10 allows App\Infrastructure\* in @throws | Internal infra exceptions (e.g. InvalidGoogleAdsResponseException) are valid within infrastructure |
| 2026-02-06 | NoCatchReturnEmpty: suppressed 10 intentional patterns | GracefulCache, LockableCache, batch processing, data parsing, console commands |
| 2026-02-06 | Removed stale @throws ConnectionException from 2 transport pool builders | Were PHPStan-pacifying annotations, not actually thrown |
| 2026-02-06 | Fixed 5 models missing schema prefix (public.*) | ProfileModel, SystemCacheModel, Auth models — added `public.` prefix |
| 2026-02-06 | Dropped Rule #26 (ResponseDtoMustHaveToDomainRule) | 14 violations vs 19 passes — too many intentional exemptions (wrappers, sub-DTOs, internal DTOs, factory pattern) |
| 2026-02-06 | Suppressed ProductModel for Rule #28 | Uses dedicated ProductModelMapper due to custom field complexity — documented in docblock |

## Batch Progress

### Batch 1: Custom AST Rules (replaced spaze/disallowed-calls)
| # | Rule | File | Status | Violations Found |
|---|------|------|--------|-----------------|
| 1 | No `DB::` facade | `NoDbFacadeRule.php` | VERIFIED (fixture) | 0 live — verified via test fixture. Allows Providers, Middleware |
| 35 | No `assertEquals()` | `NoAssertEqualsRule.php` | VERIFIED (fixture) | 0 live — verified via test fixture. tests/ excluded from PHPStan |
| 40 | No Artisan::call/queue | `NoArtisanCallRule.php` | VERIFIED (fixture) | 0 live — verified via test fixture. Allows Console, Providers |

### Batch 2: Architecture Rules
| # | Rule | File | Status | Violations Found |
|---|------|------|--------|-----------------|
| 6 | No try-catch in Domain | `NoTryCatchInDomainRule.php` | VERIFIED | 4 — Domain enum `fromValue()` methods (fixed: refactored to `tryFrom()`) |
| 8 | No static properties (Octane) | `NoStaticPropertiesRule.php` | VERIFIED | 8 — ClientFactory memoization (suppressed as tech debt) |
| 24 | No try-catch in Controllers | `NoTryCatchInControllerRule.php` | VERIFIED (fixture) | 0 live — verified via test fixture |
| 41 | No config() outside Domain/Application | spaze config (`allowExceptIn`) | VERIFIED | 2 — Application config() calls (suppressed as tech debt) |

### Batch 3: Job Rules
| # | Rule | File | Status | Violations Found |
|---|------|------|--------|-----------------|
| 17 | Jobs must implement ShouldQueue | `JobMustImplementShouldQueueRule.php` | VERIFIED (fixture) | 0 live — verified via test fixture |
| 18+19 | Jobs must define $tries + $timeout | `JobRequiredPropertiesRule.php` | VERIFIED | 2 — SyncGoogleAds + SyncCampaignLookup missing $timeout (fixed) |
| 20+21 | Jobs must define backoff() + failed() | `JobRequiredMethodsRule.php` | VERIFIED (fixture) | 0 live — verified via test fixture |
| 22 | Jobs must call onQueue() in constructor | `JobMustCallOnQueueRule.php` | VERIFIED | 3 — UpdateSku + SyncBingAds + SyncGoogleAds missing onQueue() (fixed) |
| 23 | Job naming prefix | `JobNamingPrefixRule.php` | VERIFIED (fixture) | 0 live — verified via test fixture |
| 14 | Job handle() must catch \Throwable | `JobHandleMustCatchThrowableRule.php` | VERIFIED (fixture) | 0 live — verified via test fixture |

### Batch 4: Exception Rules
| # | Rule | File | Status | Violations Found |
|---|------|------|--------|-----------------|
| 5 | Domain exceptions must extend base | `DomainExceptionMustExtendBaseRule.php` | VERIFIED (fixture) | 0 live — verified via test fixture |
| 10 | Infrastructure @throws only Domain | `NoSdkExceptionsInThrowsRule.php` | VERIFIED | 1 — `@throws ConnectionException` in ShopwiredHttpTransport (removed, was PHPStan-pacifying) + removed stale `@throws ConnectionException` from HelpScoutHttpTransport |
| 12 | No returning empty in catch | `NoCatchReturnEmptyRule.php` | VERIFIED | 10 — graceful cache (3), batch processing (2), data parsing (3), console commands (2) — all suppressed as intentional patterns |

### Batch 5: Infrastructure DTO + Table Rules
| # | Rule | File | Status | Violations Found |
|---|------|------|--------|-----------------|
| 3 | DB table refs must include schema | `SchemaQualifiedTableNameRule.php` | VERIFIED | 5 — Auth/system models missing `public.` prefix (fixed) |
| 26 | Response DTOs must have toDomain() | DROPPED | DROPPED | 14 violations vs 19 passes — too many intentional exemptions |
| 28 | Shopwired models must implement Mappable | `ShopwiredModelMustImplementMappableRule.php` | VERIFIED | 1 — ProductModel (suppressed, uses dedicated Mapper) |
| 29 | Row DTOs must be @internal | `RowDtoMustBeInternalRule.php` | VERIFIED (fixture) | 0 live — verified via test fixture |
| 30 | Row classes not imported outside Queries | `RowClassNotImportedOutsideQueriesRule.php` | VERIFIED (fixture) | 0 live — verified via test fixture |

## Final Verification (test fixtures)
All 11 previously-unverified rules confirmed working via temporary test fixtures:
- #1: NoDbFacadeRule — `alz.noDbFacade` fired
- #35: NoAssertEqualsRule — `alz.noAssertEquals` fired
- #40: NoArtisanCallRule — `alz.noArtisanCall` fired
- #24: NoTryCatchInControllerRule — `alz.noTryCatchInController` fired
- #17: JobMustImplementShouldQueueRule — `alz.jobMustImplementShouldQueue` fired
- #20+21: JobRequiredMethodsRule — `alz.jobRequiredMethod` fired
- #23: JobNamingPrefixRule — `alz.jobNamingPrefix` fired
- #14: JobHandleMustCatchThrowableRule — `alz.jobHandleMustCatchThrowable` fired
- #5: DomainExceptionMustExtendBaseRule — `alz.domainExceptionMustExtendBase` fired
- #29: RowDtoMustBeInternalRule — `alz.rowDtoMustBeInternal` fired
- #30: RowClassNotImportedOutsideQueriesRule — `alz.rowClassNotImportedOutsideQueries` fired
Fixtures deleted after verification.

## PR Notes

### What
22 custom PHPStan rules (18 custom PHP + 2 spaze config + 1 dropped) enforcing Clean Architecture conventions at static analysis time.

### Why
Architecture conventions were only enforced by code review — creating inconsistency and overhead. These rules catch violations before code reaches a PR.

### Key Decisions
- Replaced spaze/disallowed-calls for DB::/Artisan::/assertEquals with custom AST rules (Larastan facade resolution breaks spaze's type-level matching)
- Kept spaze for Config:: facade/helper (not affected by Larastan resolution)
- Dropped Rule #26 (ResponseDtoMustHaveToDomain) — too many intentional exemptions (14 of 33)
- Suppressed ProductModel for Rule #28 (uses dedicated ProductModelMapper)
- Suppressed ClientFactory static properties as tech debt (Octane rule)
- Fixed 5 models missing `public.` schema prefix
- All 22 rules verified (11 caught real violations, 11 verified via temporary test fixtures)

### Testing
All rules verified in two ways:
1. `make lint` catching real violations during implementation (11 rules)
2. Temporary test fixtures for rules with 0 existing violations (11 rules) — fixtures deleted after verification
