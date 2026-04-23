# Code Review: Issue #349 — Split RecordNotFoundException from ResourceNotFoundException

**Date:** 2026-04-23
**Branch:** feature/349-split-record-not-found-exception
**Base:** origin/develop
**Files reviewed:** 66 (64 modified + 2 new + 1 new test file created during review)

## Findings

### CRITICAL
_None._

### HIGH
_None._

### MEDIUM
_None._

### LOW
- [`app/Infrastructure/Shopwired/Repositories/EloquentOrderRepository.php:300`] snake_case `'order_refund'` resourceType inconsistent with PascalCase used elsewhere — **Fixed** (renamed to `'OrderRefund'` here and in `DeleteOrderRefundUseCaseTest.php:96`)
- [`app/Infrastructure/Notifications/Listeners/ContactFormFailedSlackListener.php`] Submission-not-found now retries 4× over ~3h instead of failing immediately — **Skipped** (intentional; aligns with the refactor's retry-on-race goal)
- [`tests/Unit/Infrastructure/Jobs/Middleware/`] No test asserting `HandleDatabaseExceptions` does NOT catch `RecordNotFoundException` — **Fixed** (new `HandleDatabaseExceptionsTest.php` covers pass-through, permanent-exception fail, and happy path)
- [`app/Presentation/Http/Api/InternalApiExceptionMapper.php:70-71,99-100`] `match (true)` ordering between `RecordNotFoundException` and `TransientApiFailure` is a load-bearing invariant — **Fixed** (added "must precede" comments on both arms)
- [`app/Domain/Exceptions/Api/ResourceNotFoundException.php`] Missing `@see RecordNotFoundException` back-reference — **Fixed** (added docblock link)

## Positive Observations

1. **Clean type-hierarchy split.** The new `RecordNotFoundException extends TransientApiFailure` is `final`, hard-codes `serviceName: 'Database'`, and keeps a static message so Sentry groups all instances into one issue (dynamic `resource_type`/`resource_id` ride on `context()`). Mirrors the existing `ResourceNotFoundException` pattern precisely.

2. **Zero middleware changes required.** Because `HandleApiExceptions` already discriminates on `TransientApiFailure`/`PermanentApiFailure` base types, adding the new leaf automatically routes to the retry path — no edits to the decision point. Classic Open/Closed.

3. **PHPStan-driven @throws propagation.** 25+ files picked up `@throws RecordNotFoundException` after the throw-site swap, guided by `missingType.checkedException`. Interfaces (`ProductRepositoryInterface`, `OrderRepositoryInterface`, etc.) stayed accurate against implementations.

4. **Smart carve-out in `InternalApiExceptionMapper`.** Without the carve-out, synchronous internal-API GETs against a missing DB row would have regressed from 404 → 503 (since `TransientApiFailure` maps to 503). `RecordNotFoundException` now preserves 404 semantics *and* its own user-facing message.

5. **Compensation flow in `UpdateSkuUseCase` is tight.** The catch union was widened to include `RecordNotFoundException`, keeping the Linnworks compensation guarantee intact when the ShopWired client's local-DB resolve fails. The inner compensation catch deliberately omits `RecordNotFoundException` because the Linnworks `updateSku()` compensation call doesn't throw it — a correct narrowing.

6. **Test pyramid respected.** Domain-level exception test pins `context()`/`serviceName`/message invariants; middleware test asserts transient-release path; mapper test pins the 404 response; use-case tests pin idempotency.

## Summary

Well-executed, mechanically-consistent refactor. The bug (transient DB-races failing immediately) is addressed by a minimal API surface change — one new exception, throw-site swaps, catch-site swaps, and targeted docblock propagation. No CRITICAL/HIGH/MEDIUM issues; five LOW-severity polish items surfaced, four resolved inline during review. PR is ship-ready pending the standard `make lint` / `make test` / stop-hook cycle.
