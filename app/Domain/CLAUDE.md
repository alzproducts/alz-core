# Domain Layer

## Purpose
Domain exceptions represent **business concepts**: service unavailable, authentication expired, insufficient stock, invalid state. They express what the business understands, not technical details. Framework-agnostic, zero external dependencies.

## What Belongs in Domain

- ✅ **Business rule violations** — e.g. `InsufficientStockException` with `productId`, `requested`, `available`
- ✅ **External service failures** (as business concept) — e.g. `ExternalServiceUnavailableException` with `serviceName`, `retryAfter`
- ✅ **API contract violations** (programming errors) — `InvalidApiResponseException` with `serviceName`. Should NOT retry (permanent until code changes). Signals "code needs updating for new API version"
- ✅ **Authentication/Authorization** — e.g. `AuthenticationExpiredException` with `serviceName`
- ✅ **Domain state violations** — e.g. `OrderCannotBeCancelledException` with `orderId`, `currentStatus`

## Exception Design Rules

- Must be `final` classes with `readonly` constructor-promoted properties carrying business context (IDs, amounts, status)
- Extend `\DomainException` or `\LogicException`
- Use **named constructors** (`::fromFailedRecords()`) for complex creation logic
- Document `@throws` on interface methods

## What NOT to Put in Domain

- ❌ **Generic wrappers** (`SyncFailedException`, `ApiErrorException`) — use specific business states instead
- ❌ **Framework dependencies** — never extend `\Illuminate\*` exceptions, extend `\DomainException`
- ❌ **Infrastructure details** (`RateLimitException`, `HttpTimeoutException`) — use `ExternalServiceUnavailableException` with `retryAfter`

## Domain Rarely Catches

Domain primarily **throws**, not catches. The exception: catching PHP built-in exceptions (e.g., `\InvalidArgumentException`, `\DateMalformedStringException`) to rethrow as domain-specific exceptions. No catching of domain exceptions within the domain layer itself.

## Assertions vs Exceptions

- **Assertions** (`webmozart/assert`): Programming errors, developer mistakes, internal contract violations
- **Exceptions**: Business rule violations, runtime conditions — always active

## Validators

Live in `Validators/` subdirectories alongside their domain concept (e.g., `Catalog/Product/Validators/`). Validation infrastructure (contracts, traits) in `Shared/Validation/` — see `Shared/Validation/CLAUDE.md`.

## Integer IDs

**Use `IntId` value object** for all integer identifiers, not primitive `int`.
