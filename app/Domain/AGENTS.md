# Domain Layer

## Purpose
Domain exceptions represent **business concepts**: service unavailable, authentication expired, insufficient stock, invalid state. They express what the business understands, not technical details. Framework-agnostic, zero external dependencies.

## What Belongs in Domain

- ✅ **Business rule violations** — e.g. `InsufficientStockException` with `productId`, `requested`, `available`
- ✅ **External service failures** (as business concept) — e.g. `ExternalServiceUnavailableException` with `serviceName`, `retryAfter`
- ✅ **API contract violations** (programming errors) — `InvalidApiResponseException` with `serviceName`. Should NOT retry (permanent until code changes). Signals "code needs updating for new API version"
- ✅ **Authentication/Authorization** — e.g. `AuthenticationExpiredException` with `serviceName`
- ✅ **Domain state violations** — e.g. `OrderCannotBeCancelledException` with `orderId`, `currentStatus`

## What NOT to Put in Domain

- ❌ **Generic wrappers** (`SyncFailedException`, `ApiErrorException`) — use specific business states instead
- ❌ **Framework dependencies** — never extend `\Illuminate\*` exceptions, extend `\DomainException`
- ❌ **Infrastructure details** (`RateLimitException`, `HttpTimeoutException`) — use `ExternalServiceUnavailableException` with `retryAfter`

> Exception design rules → `.claude/rules/domain-exceptions.md` (auto-loads on `app/Domain/**/Exceptions/**/*.php`)

## Domain Rarely Catches

Domain primarily **throws**, not catches. The exception: catching PHP built-in exceptions (e.g., `\InvalidArgumentException`, `\DateMalformedStringException`) to rethrow as domain-specific exceptions. No catching of domain exceptions within the domain layer itself.

## Assertions vs Exceptions

- **Assertions** (`webmozart/assert`): Programming errors, developer mistakes, internal contract violations
- **Exceptions**: Business rule violations, runtime conditions — always active

> Validator patterns → `.claude/rules/domain-validators.md` (auto-loads on `app/Domain/**/Validators/*.php`)

## Native Domain Types

All new domain code MUST use native domain types instead of primitives. Existing VOs are NOT retroactively updated unless explicitly scoped.

| Concept | Domain Type | Namespace | NOT |
|---------|------------|-----------|-----|
| Entity/external IDs | `IntId` | `App\Domain\ValueObjects` | `int` |
| UUID identifiers | `Guid` | `App\Domain\ValueObjects` | `string` |
| Monetary values | `Money` | `App\Domain\Shared\Money\ValueObjects` | `float` |
| Product identifiers | `Sku` | `App\Domain\Catalog\Product\ValueObjects` | `string` |
| Barcodes | `Gtin` | `App\Domain\Catalog\Product\ValueObjects` | `string` |
| Weight | `Weight` | `App\Domain\Inventory\ValueObjects` | `float` |
| Dimensions | `Dimensions` | `App\Domain\Inventory\ValueObjects` | `float` |
| Tax treatment | `TaxType` | `App\Domain\ValueObjects` | `string`/`bool` |
| Tax rate | `TaxRate` | `App\Domain\ValueObjects` | `float` |
| Date ranges | `DateRange` | `App\Domain\ValueObjects` | two `DateTimeImmutable` |

### Money Tax Type Selection

When creating `Money` instances, the tax type must be explicitly chosen:

| Scenario | Constructor | Example |
|----------|-----------|---------|
| Customer-facing prices (incl VAT) | `Money::inclusive()` | Order total, sale price, refund value |
| Trade/cost/net prices (excl VAT) | `Money::exclusive()` | Cost price, subtotal net, shipping net |
| Tax amounts themselves | `Money::zeroRated()` | VAT value, priceVat, totalVat |
| Items exempt from VAT | `Money::zeroRated()` | Zero-rated goods (books, children's clothing) |
| Nullable "not set" amounts | `Money::nonZeroOrNull()` | Optional cost price, sale price |
