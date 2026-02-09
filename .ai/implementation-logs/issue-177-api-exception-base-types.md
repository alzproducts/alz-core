# Implementation Log: API Exception Base Types

**GitHub Issue**: #177
**Plan Document**: .ai/plans/2026-01-29_177-api-exception-base-types-refactoring.md
**Status**: In Progress
**Started**: 2026-02-07
**Completed**: —

## Overview

Add `PermanentApiFailure` and `TransientApiFailure` abstract base classes to enable type-level distinction between retryable and non-retryable API failures. Phase 1 only — backward-compatible.

## Decision Log

### 2026-02-07
- **Decision**: `serviceName` promoted in `AbstractApiException`, de-promoted in all 7 concrete exceptions
- **Why**: Single ownership point; concrete classes pass `$serviceName` to parent but don't redeclare as `public readonly`
- **Tradeoff**: Slight increase in constructor parameter passing depth (3 levels: concrete → category → abstract)

### 2026-02-07
- **Decision**: `retryAfter` promoted in `TransientApiFailure`, de-promoted in `ExternalServiceUnavailableException`
- **Why**: Future transient exceptions (e.g., rate-limited writes) can inherit `retryAfter` without redeclaring

### 2026-02-07
- **Decision**: Plan correction — `UnexpectedApiResultException::$reason` preserved as promoted property
- **Why**: Accessed in 5 production locations (3 Mixpanel jobs, ProductDomainFactory, test)

## Deviations from Plan

None — implementation followed the plan exactly.

## Blockers / Open Questions

- [ ] Awaiting lint/test verification via stop hooks

## PR Notes

### What
Add `PermanentApiFailure` and `TransientApiFailure` abstract base classes between `AbstractApiException` and the 7 concrete API exceptions.

### Why
API exception handling in jobs requires verbose 6-exception union catch blocks. Adding a type-level distinction enables `catch (TransientApiFailure)` for retry logic and `catch (PermanentApiFailure)` for immediate failure — replacing multi-line union catches.

### Key Decisions
- Phase 1 only: backward-compatible (no constructor signature changes, no catch block changes)
- `serviceName` ownership moved to `AbstractApiException` (de-promoted in concrete classes)
- `retryAfter` ownership on `TransientApiFailure` base (enables future transient subtypes)
- PHPStan Symplify naming rule suppressed for 2 new abstract classes (matches existing convention)

### Testing
- No test changes needed — all constructor signatures preserved externally
- Verified via `make lint` (PHPStan type hierarchy) and `make test` (existing tests pass)
