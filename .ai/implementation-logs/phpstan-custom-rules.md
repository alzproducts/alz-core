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
| 17 | Jobs must implement ShouldQueue | `JobMustImplementShouldQueueRule.php` | | |
| 18+19 | Jobs must define $tries + $timeout | `JobRequiredPropertiesRule.php` | | |
| 20+21 | Jobs must define backoff() + failed() | `JobRequiredMethodsRule.php` | | |
| 22 | Jobs must call onQueue() in constructor | `JobMustCallOnQueueRule.php` | | |
| 23 | Job naming prefix | `JobNamingPrefixRule.php` | | |
| 14 | Job handle() must catch \Throwable | `JobHandleMustCatchThrowableRule.php` | | |

### Batch 4: Exception Rules
| # | Rule | File | Status | Violations Found |
|---|------|------|--------|-----------------|
| 5 | Domain exceptions must extend base | `DomainExceptionMustExtendBaseRule.php` | | |
| 10 | Infrastructure @throws only Domain | `NoSdkExceptionsInThrowsRule.php` | | |
| 12 | No returning empty in catch | `NoCatchReturnEmptyRule.php` | | |

### Batch 5: Infrastructure DTO + Table Rules
| # | Rule | File | Status | Violations Found |
|---|------|------|--------|-----------------|
| 3 | DB table refs must include schema | `SchemaQualifiedTableNameRule.php` | | |
| 26 | Response DTOs must have toDomain() | `ResponseDtoMustHaveToDomainRule.php` | | |
| 28 | Shopwired models must implement Mappable | `ShopwiredModelMustImplementMappableRule.php` | | |
| 29 | Row DTOs must be @internal | `RowDtoMustBeInternalRule.php` | | |
| 30 | Row classes not imported outside Queries | `RowClassNotImportedOutsideQueriesRule.php` | | |

## Unverified Rules (need test fixtures)
- #1: No DB:: facade (allowIn paths cover all current usage)
- #35: No assertEquals() (tests excluded from PHPStan)
- #40: No Artisan::call() (no current usage — preventive)
- #24: No try-catch in Controllers (no controllers use try-catch)

## PR Notes
_To be drafted after implementation_
