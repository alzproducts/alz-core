# Infrastructure Layer

## Eloquent Repositories

> Eloquent repository patterns → `.claude/rules/eloquent-repositories.md` (auto-loads on `Eloquent*Repository.php`)

## Exception Messages

- **Static messages** — When throwing domain exceptions, keep messages static. Pass dynamic details (IDs, field names, error reasons) as constructor parameters — they become context, not message content.

## Exception Handling: Catch and Translate

Infrastructure **always catches** SDK/HTTP exceptions and **translates** to Domain exceptions. This is where technical → business translation happens. The try-catch lives in dedicated `*HttpTransport.php` classes — clients delegate via their `*TransportInterface` and never inline try-catch themselves.

> HTTP transport exception handling → `.claude/rules/infrastructure-http-transports.md` (auto-loads on `*Transport.php`)

> Nested DTO validation pattern → `.claude/rules/infrastructure-response-parsers.md` (auto-loads on `*ResponseParserTrait.php`)

### Critical Rules

- ✅ Always log before translating — SDK details won't exist in Domain exception
- ❌ Never let SDK exceptions escape to Application layer
- ❌ Never return empty arrays to hide failures — throw exceptions

## Configuration Validation

> Client factory config validation → `.claude/rules/infrastructure-client-factories.md` (auto-loads on `*ClientFactory.php`)

## Spatie LaravelData

> Response DTO conventions → `.claude/rules/infrastructure-response-dtos.md` (auto-loads on `Responses/**/*Response.php`)

## Domain-to-Model Mapping & Bulk Inserts

> Eloquent model mapping patterns (`attributesFromDomain`, bulk insert timestamps) → `.claude/rules/eloquent-repositories.md` (auto-loads on `Eloquent*Repository.php`)

## Client Contracts: Structural Mapping Only

Application-layer interfaces accept **pre-resolved** commands containing opaque external IDs and domain values — the UseCase orchestrates all resolution (SKU→ID, supplier→ID) via separate resolver interfaces. The Infrastructure client performs **only structural mapping** via a `final readonly` Request class with a static factory.

**Resolution is orchestration; orchestration belongs in the UseCase.**

> Request class contract → `.claude/rules/infrastructure-requests.md` (auto-loads on `Infrastructure/**/Requests/*.php`)

**Golden Rule**: Nothing leaves Infrastructure without a Domain exception passport.
