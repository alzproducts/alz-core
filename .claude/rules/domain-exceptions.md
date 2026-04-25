---
paths:
  - "app/Domain/**/Exceptions/**/*.php"
---

# Domain — Exception Class Rules

## Class Shape

- DO declare concrete exceptions `final` with `readonly` constructor-promoted properties carrying business context (IDs, amounts, status, service name, retry hint). Abstract bases (e.g. `AbstractApiException`, `PermanentApiFailure`, `TransientApiFailure`, `AbstractInfrastructureException`) hold shared shape and are not final.
- DO keep exception messages as static strings — no interpolated IDs, names, or other dynamic data. **Why:** static messages enable Sentry to group occurrences; interpolated values explode the group count.
- DO surface dynamic data via readonly properties returned from `context(): array`.
- DO extend `App\Domain\Exceptions\DomainException` (or an abstract child like `PermanentApiFailure`, `TransientApiFailure`, `AbstractInfrastructureException`) for business/runtime failures.
- DO extend `\LogicException` for programming errors — impossible states, coded mismatches, fields that should never reach this branch. **Why:** `\LogicException` signals "this fired because the code is wrong, not because the runtime broke."

Canonical: `AuthenticationExpiredException` (DomainException chain via PermanentApiFailure → AbstractApiException → DomainException), `UnsupportedFieldException` (\LogicException).
