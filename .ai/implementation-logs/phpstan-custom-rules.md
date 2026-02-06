# PHPStan Custom Rules — Implementation Log

## Status: In Progress

## Decision Log

| Date | Decision | Rationale |
|------|----------|-----------|
| 2026-02-06 | Use `spaze/phpstan-disallowed-calls` for config-only rules | Already installed v4.7, avoids custom PHP code |
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

### Batch 1: CONFIG Rules (phpstan.neon only)
| # | Rule | Status | Violations Found |
|---|------|--------|-----------------|
| 1 | No `DB::` facade | UNVERIFIED | 0 — all DB:: usage in allowed paths (Providers, Middleware) |
| 35 | No `assertEquals()` | UNVERIFIED | 0 — tests/ excluded from PHPStan analysis |
| 40 | No destructive artisan commands | UNVERIFIED | 0 — no Artisan::call() in app code (preventive) |

### Batch 2: Architecture Rules
| # | Rule | File | Status | Violations Found |
|---|------|------|--------|-----------------|
| 6 | No try-catch in Domain | `NoTryCatchInDomainRule.php` | VERIFIED | 4 — Domain enum `fromValue()` methods (fixed: refactored to `tryFrom()`) |
| 8 | No static properties (Octane) | `NoStaticPropertiesRule.php` | VERIFIED | 8 — ClientFactory memoization (suppressed as tech debt) |
| 24 | No try-catch in Controllers | `NoTryCatchInControllerRule.php` | UNVERIFIED | 0 — no controllers use try-catch |
| 41 | No config() outside Domain/Application | spaze config (`allowExceptIn`) | VERIFIED | 2 — Application config() calls (suppressed as tech debt) |

### Batch 3: Job Rules
| # | Rule | File | Status | Violations Found |
|---|------|------|--------|-----------------|
| 17 | Jobs must implement ShouldQueue | `JobMustImplementShouldQueueRule.php` | UNVERIFIED | 0 — all jobs already implement ShouldQueue |
| 18+19 | Jobs must define $tries + $timeout | `JobRequiredPropertiesRule.php` | VERIFIED | 2 — SyncGoogleAds + SyncCampaignLookup missing $timeout (fixed) |
| 20+21 | Jobs must define backoff() + failed() | `JobRequiredMethodsRule.php` | UNVERIFIED | 0 — all jobs have backoff + failed() |
| 22 | Jobs must call onQueue() in constructor | `JobMustCallOnQueueRule.php` | VERIFIED | 3 — UpdateSku + SyncBingAds + SyncGoogleAds missing onQueue() (fixed) |
| 23 | Job naming prefix | `JobNamingPrefixRule.php` | UNVERIFIED | 0 — all job names follow convention |
| 14 | Job handle() must catch \Throwable | `JobHandleMustCatchThrowableRule.php` | UNVERIFIED | 0 — all jobs have catch(Throwable) |

### Batch 4: Exception Rules
| # | Rule | File | Status | Violations Found |
|---|------|------|--------|-----------------|
| 5 | Domain exceptions must extend base | `DomainExceptionMustExtendBaseRule.php` | UNVERIFIED | 0 — all domain exceptions correctly extend hierarchy |
| 10 | Infrastructure @throws only Domain | `NoSdkExceptionsInThrowsRule.php` | VERIFIED | 1 — `@throws ConnectionException` in ShopwiredHttpTransport (removed, was PHPStan-pacifying) + removed stale `@throws ConnectionException` from HelpScoutHttpTransport |
| 12 | No returning empty in catch | `NoCatchReturnEmptyRule.php` | VERIFIED | 10 — graceful cache (3), batch processing (2), data parsing (3), console commands (2) — all suppressed as intentional patterns |

### Batch 5: Infrastructure DTO + Table Rules
| # | Rule | File | Status | Violations Found |
|---|------|------|--------|-----------------|
| 3 | DB table refs must include schema | `SchemaQualifiedTableNameRule.php` | VERIFIED | 5 — Auth/system models missing `public.` prefix (fixed) |
| 26 | Response DTOs must have toDomain() | DROPPED | DROPPED | 14 violations vs 19 passes — too many intentional exemptions |
| 28 | Shopwired models must implement Mappable | `ShopwiredModelMustImplementMappableRule.php` | VERIFIED | 1 — ProductModel (suppressed, uses dedicated Mapper) |
| 29 | Row DTOs must be @internal | `RowDtoMustBeInternalRule.php` | UNVERIFIED | 0 — only Row DTO already has @internal |
| 30 | Row classes not imported outside Queries | `RowClassNotImportedOutsideQueriesRule.php` | UNVERIFIED | 0 — no Row imports outside Queries |

## Unverified Rules (need test fixtures)
- #1: No DB:: facade (allowIn paths cover all current usage)
- #35: No assertEquals() (tests excluded from PHPStan)
- #40: No Artisan::call() (no current usage — preventive)
- #24: No try-catch in Controllers (no controllers use try-catch)
- #17: Jobs must implement ShouldQueue (all already do)
- #20+21: Jobs must define backoff() + failed() (all already do)
- #23: Job naming prefix (all follow convention)
- #14: Job handle() must catch \Throwable (all already do)
- #5: Domain exceptions must extend base (all already do)
- #29: Row DTOs must be @internal (only Row DTO already has it)
- #30: Row classes not imported outside Queries (no external imports)

## PR Notes
_To be drafted after implementation_
