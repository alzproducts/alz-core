# Custom PHPStan Rules — Implementation Plan

## Workflow

**Iterative batch approach:**
1. Implement rules in small groups (5 batches)
2. After each batch: `make lint` to see if rules catch existing violations
3. If violations found → fix them → rule confirmed working
4. If no violations found → rule is unverified → add to implementation log
5. After all batches done → create test fixture files for unverified rules
6. Run PHPStan on fixtures → confirm all rules actually trigger

**Implementation log**: `.ai/implementation-logs/phpstan-custom-rules.md` — tracks which rules are verified vs need testing.

---

## Batch 1: CONFIG Rules (phpstan.neon only)

No PHP code. Add to `phpstan.neon` via `spaze/phpstan-disallowed-calls`.

| # | Rule | Config key |
|---|------|-----------|
| 1 | **No `DB::` facade** — use `DatabaseGateway` | `disallowedStaticCalls` |
| 35 | **No `assertEquals()`** — use `assertSame()` | `disallowedMethodCalls` |
| 40 | **No destructive artisan commands** | `disallowedStaticCalls` |

**After:** `make lint` → fix any violations → log unverified rules.

---

## Batch 2: Architecture Rules

| # | Rule | Complexity | File |
|---|------|-----------|------|
| 6 | **No try-catch in Domain** | SIMPLE | `NoTryCatchInDomainRule.php` |
| 8 | **No static properties** (Octane) | SIMPLE | `NoStaticPropertiesRule.php` |
| 24 | **No try-catch in Controllers** | SIMPLE | `NoTryCatchInControllerRule.php` |
| 41 | **No `config()`/`Config::get()` outside Providers** | MEDIUM | `NoConfigOutsideProvidersRule.php` |

Rules #6, #8, #24 use the same pattern: detect an AST node in a specific namespace.
Rule #41 detects `config()` function calls and `Config::get()` static calls, allowing them only in:
- `app/Providers/`
- `config/`
- `tests/`

**After:** `make lint` → fix violations → log unverified.

---

## Batch 3: Job Rules

| # | Rule | Complexity | File |
|---|------|-----------|------|
| 17 | **Jobs must implement ShouldQueue** | SIMPLE | `JobMustImplementShouldQueueRule.php` |
| 18+19 | **Jobs must define $tries + $timeout** | SIMPLE | `JobRequiredPropertiesRule.php` |
| 20+21 | **Jobs must define backoff() + failed()** | SIMPLE | `JobRequiredMethodsRule.php` |
| 22 | **Jobs must call onQueue() in constructor** | MEDIUM | `JobMustCallOnQueueRule.php` |
| 23 | **Job naming prefix** (Sync/Process/Reconcile) | SIMPLE | `JobNamingPrefixRule.php` |
| 14 | **Job handle() must catch \Throwable** | MEDIUM | `JobHandleMustCatchThrowableRule.php` |

**After:** `make lint` → fix violations → log unverified.

---

## Batch 4: Exception Rules

| # | Rule | Complexity | File |
|---|------|-----------|------|
| 5 | **Domain exceptions must extend project base** | MEDIUM | `DomainExceptionMustExtendBaseRule.php` |
| 10 | **Infrastructure @throws must only be Domain exceptions** | MEDIUM | `NoSdkExceptionsInThrowsRule.php` |
| 12 | **No returning empty/null in catch blocks** | MEDIUM | `NoCatchReturnEmptyRule.php` |

**After:** `make lint` → fix violations → log unverified.

---

## Batch 5: Infrastructure DTO + Table Rules

| # | Rule | Complexity | File |
|---|------|-----------|------|
| 3 | **DB table refs must include schema** (`.` required) | MEDIUM | `SchemaQualifiedTableNameRule.php` |
| 26 | **Response DTOs must have toDomain()** | MEDIUM | `ResponseDtoMustHaveToDomainRule.php` |
| 28 | **Shopwired models → EloquentDomainMappableInterface** | SIMPLE | `ShopwiredModelMustImplementMappableRule.php` |
| 29 | **Row DTOs must be @internal** | SIMPLE | `RowDtoMustBeInternalRule.php` |
| 30 | **Row classes not imported outside Queries** | MEDIUM | `RowClassNotImportedOutsideQueriesRule.php` |

**After:** `make lint` → fix violations → log unverified.

---

## Final Phase: Test Fixtures for Unverified Rules

Create test fixture files that deliberately violate each unverified rule:

```
tests/PHPStan/Fixtures/
├── DomainWithTryCatch.php
├── ControllerWithTryCatch.php
├── ClassWithStaticProperty.php
├── JobWithoutShouldQueue.php
├── JobWithoutRequiredProperties.php
├── JobWithoutRequiredMethods.php
├── JobWithoutOnQueue.php
├── JobWithWrongPrefix.php
├── JobHandleWithoutThrowableCatch.php
├── DomainExceptionWithWrongBase.php
├── InfrastructureWithSdkThrows.php
├── CatchReturningEmpty.php
├── UnqualifiedTableName.php
├── ResponseDtoWithoutToDomain.php
├── ShopwiredModelWithoutInterface.php
├── RowDtoWithoutInternal.php
└── RowImportedOutsideQueries.php
```

Run PHPStan on each fixture → confirm error is reported.

---

## File Structure

```
app/DevTools/PHPStan/Rules/
├── Architecture/
│   ├── NoTryCatchInDomainRule.php
│   ├── NoStaticPropertiesRule.php
│   ├── NoTryCatchInControllerRule.php
│   ├── NoConfigOutsideProvidersRule.php
│   └── SchemaQualifiedTableNameRule.php
├── Jobs/
│   ├── JobMustImplementShouldQueueRule.php
│   ├── JobRequiredPropertiesRule.php
│   ├── JobRequiredMethodsRule.php
│   ├── JobMustCallOnQueueRule.php
│   ├── JobNamingPrefixRule.php
│   └── JobHandleMustCatchThrowableRule.php
├── Exceptions/
│   ├── DomainExceptionMustExtendBaseRule.php
│   └── NoCatchReturnEmptyRule.php
└── Infrastructure/
    ├── NoSdkExceptionsInThrowsRule.php
    ├── ResponseDtoMustHaveToDomainRule.php
    ├── ShopwiredModelMustImplementMappableRule.php
    ├── RowDtoMustBeInternalRule.php
    └── RowClassNotImportedOutsideQueriesRule.php

phpstan-custom-rules.neon          (service registration)
tests/PHPStan/Fixtures/            (test fixtures for unverified rules)
```

---

## Registration

`phpstan-custom-rules.neon` (included by `phpstan.neon`):
```yaml
services:
    - class: App\DevTools\PHPStan\Rules\Architecture\NoTryCatchInDomainRule
      tags: [phpstan.rules.rule]
    # ... all rules registered here
```

---

## Summary

| Batch | Rules | Count |
|-------|-------|-------|
| 1: CONFIG | #1, #35, #40 | 3 |
| 2: Architecture | #6, #8, #24, #41 | 4 |
| 3: Jobs | #17, #18+19, #20+21, #22, #23, #14 | 6 (8 rules, 6 files) |
| 4: Exceptions | #5, #10, #12 | 3 |
| 5: Infra DTOs | #3, #26, #28, #29, #30 | 5 |
| Final: Test fixtures | Unverified rules only | TBD |
| **Total** | | **23 rules, ~18 files** |

### Deferred
- #11: Catch-just-to-log-and-rethrow
- #25: Controller must call exactly one use case
- #36: No assertTrue() for specific assertions
