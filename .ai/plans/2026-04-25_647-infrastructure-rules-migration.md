# Infrastructure Layer — Scoped Rules Migration

## Context

`.claude/rules/` path-scoped rules have been proven by two prior migrations — Eloquent (#641→#642) and Presentation (#643→#644/#645). Both ported file-shape conventions out of always-loaded `CLAUDE.md` files into globbed rule files that auto-load only when Claude opens a matching file, eliminating token cost for irrelevant context.

`app/Infrastructure/CLAUDE.md` is the next candidate. Unlike the prior two migrations, a design-review pass (see *Grill Notes* at the bottom) revealed the source document has **drifted materially** from the canonical code: wrong exception class names, wrong factory method names, tables of HTTP status mappings that omit two of the five actual translations, and — most structurally — a catch-and-translate pattern attributed to `*Client.php` files that has in fact been refactored out into dedicated `*Transport.php` classes and `*ResponseParserTrait.php` traits.

This migration therefore runs in **two stages within one PR**:

- **Stage 1 — Correct `app/Infrastructure/CLAUDE.md` in place.** Fix the 13 drift items listed below. At the end of stage 1 the source document is accurate but still loads globally.
- **Stage 2 — Extract the now-accurate rules into scoped `.claude/rules/` files** and replace each migrated section in `CLAUDE.md` with a one-line pointer.

Nested integration `CLAUDE.md` files (`Shopwired/`, `HelpScout/`, `Mixpanel/`, `Linnworks/`, `Linnworks/Queries/`, `BingAds/`, `GoogleAds/`, `ReviewsIo/`, `Jobs/`, `Shopwired/Models/`) stay as-is per the issue — their directory location already scopes them.

## Design Principle

**Scoped rules point at canonical code; they do not duplicate it.**

This is already the authoring guidance in `.claude/rules/CLAUDE.md` — *"Don't list every method on a gateway Claude is calling — 'check the gateway first' is enough."* — but the original draft of this plan violated it by transcribing the source CLAUDE.md's mapping tables and signature examples into scoped rules. The grill retroactively corrected that.

Concretely: no HTTP-status → exception-class tables. No specific Spatie exception class names. No `buildBulkPayload(Guid, array)` example. Instead, rules point at the canonical transport, trait, or factory — the code is the authoritative source, and stays accurate as the code evolves.

Consequences the rule files inherit:

- The translation matrix lives in `LinnworksHttpTransport` and `ShopwiredHttpTransport`. The rule says "translate; see the canonical transports for the current matrix."
- The nested DTO validation class lives in `LinnworksResponseParserTrait::mapDtosFromArray()`. The rule says "parse via the trait pattern; see the canonical trait for the exact Spatie exception class currently caught." (Avoids breakage when Spatie renames `CannotCreateData` again.)
- Factory names vary (`fromCommand`, `fromDomain`, `fromResolved`). The rule says "name after input source; see neighbouring `Requests/*.php`."
- Response DTO `MapInputName` mapper varies by integration (`PascalCaseMapper` for Linnworks, `SnakeCaseMapper` for Shopwired). The rule says "use an appropriate `MapInputName` mapper; see neighbours."

## Locked-in Decisions

Captured during `/assess` and refined through the grill:

- **Five new rule files** — `infrastructure-http-transports.md`, `infrastructure-response-parsers.md`, `infrastructure-client-factories.md`, `infrastructure-requests.md`, `infrastructure-response-dtos.md`.
- **No `infrastructure-api-clients.md`** — originally planned; abandoned when the grill showed `*Client.php` is a mixed bag (HTTP façades delegating to transports, plus Storage/Slack/Shopwired façades that don't share the pattern) and the catch-and-translate rule actually lives in `*Transport.php`.
- **Drift corrections** (the 13-item catalog below) happen in Stage 1 against `app/Infrastructure/CLAUDE.md`, before extraction. PR reviewer can audit each correction against the canonical code before seeing the extraction diff.
- **Rewrite style:** strict DO / DO NOT / EXCEPTION bullets matching `eloquent-repositories.md` and `presentation-controllers.md`. No mapping tables, no specific exception class names, no signature examples — point at canonical classes instead.
- **Promote `DomainConvertibleInterface` requirement** into `infrastructure-response-dtos.md` — it's currently only in the nested `Shopwired/CLAUDE.md`, but the same requirement applies universally to Linnworks Response DTOs (the parser traits depend on `toDomain()` existing). Widening here is justified because the parser-trait contract breaks silently without it.
- **Pointer style:** each new rule gets a one-line pointer in `app/Infrastructure/CLAUDE.md`, same shape as the existing Eloquent pointer on line 5.
- **Commit structure:** two commits in one PR — (1) drift corrections, (2) extraction.

## Stage 1 — Changes to Existing Instructions in `app/Infrastructure/CLAUDE.md`

Commit 1 applies these corrections verbatim against the current `app/Infrastructure/CLAUDE.md`. No scoped-rule files are created in this commit; the document simply becomes accurate in place.

| # | Line(s) | Current text (quote / summary) | Correction | Evidence |
|---|---|---|---|---|
| 1 | 15 | "Wrap all external API/SDK calls in try-catch" | Keep the directive. Reframe the containing section to clarify that the try-catch lives in the HTTP transport layer, not the client. | `Linnworks/Clients/OrderClient.php` has no try-catch; delegates to `LinnworksTransportInterface`. Same for Shopwired. |
| 2 | 16 | "Log technical details first" | Keep verbatim. | Matches `LinnworksHttpTransport` behaviour. |
| 3 | 19–22 | Table of 3 status → exception mappings (rate-limit / auth / connection) | **Delete the table.** Replace with: "Translate SDK/HTTP exceptions to Domain API exceptions — see `LinnworksHttpTransport` / `ShopwiredHttpTransport` catch-blocks for the current full matrix." | Transports actually translate 5+ cases (400, 401/403, 404, 429/rate-limit, connection) — table was incomplete. Principle: rules don't duplicate code. |
| 4 | 24 | "When parsing API responses through Spatie DTOs (`::from()`), use a nested try-catch" | Reframe location: the nested catch lives in `*ResponseParserTrait.php`, not in client methods. Clients call trait methods (`self::parseWrappedArrayToDomain(...)`); they don't inline `::from()` in a try-catch. | `LinnworksResponseParserTrait::mapDtosFromArray()` catches the Spatie exception; `Linnworks/Clients/OrderClient.php` has no direct `::from()` call. |
| 5 | 27 | "Inner catch around `SomeResponse::from($row)` catches `ValidationException`" | Replace class name: Spatie throws `Spatie\LaravelData\Exceptions\CannotCreateData` (current class name). Drop the specific class name from CLAUDE.md prose and point at `LinnworksResponseParserTrait` as the canonical reference. | `LinnworksResponseParserTrait` line 15 imports and catches `CannotCreateData`. |
| 6 | 28 | "Log at **CRITICAL** level — code needs immediate update" | Keep verbatim. | Matches `Log::critical(...)` in the trait. |
| 7 | 29 | "Include **raw response** in log (needed to fix the DTO)" | Replace with: "Log the error message plus `get_debug_type($response)` — do NOT log the raw response (PII and log-size risk)." | `LinnworksResponseParserTrait::logParsingFailure()` logs `['error' => ..., 'response_type' => get_debug_type(...)]` — no raw data. Current code reflects deliberate PII / log-size posture; the CLAUDE.md advice is dangerously wrong. |
| 8 | 30 | "Throw `InvalidApiResponseException` — do NOT retry (permanent until code changes)" | Keep verbatim. | Matches reality. |
| 9 | 41 | "Use `RuntimeException` for missing/invalid config values — these are programming mistakes, not runtime conditions." | Replace: "Use `App\Domain\Exceptions\InvalidConfigurationException($envVar)` for missing/invalid config values. Config reading and validation happens in `*ClientFactory.php`, not in clients or transports." | `LinnworksClientFactory::requireStringConfig()` throws `InvalidConfigurationException`, not `RuntimeException`. |
| 10 | 45 | "Supports `#[MapInputName(SnakeCaseMapper::class)]` for property mapping." | Replace with: "Supports class-level `#[MapInputName(...)]` with a mapper class appropriate for the third party — e.g. `SnakeCaseMapper` (Shopwired), custom `PascalCaseMapper` (Linnworks). See neighbouring Response DTOs for the right choice." | Linnworks uses `App\Infrastructure\Linnworks\Support\PascalCaseMapper`; Shopwired uses Spatie's built-in `SnakeCaseMapper`. Neither is universal. |
| 11 | 55 | "`InfraRequest::fromResolved($resolvedId, $domainValue, ...)→toArray()`" | Replace: the factory name varies by input source — `fromCommand(DomainCommand)`, `fromDomain(DomainVO)`, or `fromResolved(...)` when pre-resolved IDs + value objects are passed explicitly. No Request in the repo is actually named `fromResolved`; list all three as valid patterns and point at neighbouring `Requests/*.php`. | `AddInventoryItemRequest::fromCommand`, `UpdateStockSupplierStatRequest::fromDomain`. |
| 12 | 63–84 | Full `SomeApiRequest` class example with `fromResolved(string $id, Guid $guid, Money $price)` signature and `buildBulkPayload(Guid $guid, array $itemPrices)` signature | **Delete the full code example.** Replace with: "Structure: `final readonly` class, private constructor, static factory named after input source, `toArray()` returning API-shaped keys. For bulk endpoints: expose `public static buildBulkPayload(...)` — the parameter list mirrors the caller's bulk client method. Canonical: `AddInventoryItemRequest`, `UpdateStockSupplierStatRequest::buildBulkPayload`." | Example signatures don't match any real Request in the repo. Principle: point at canonical, don't invent signatures. |
| 13 | 17 | Implicit: the catch-and-translate pattern is a *client* responsibility | Reframe the section's opening prose so it's clear the try-catch lives in dedicated `*HttpTransport.php` classes; clients delegate via `TransportInterface`. Retain the architectural framing ("Infrastructure is where technical → business exception translation happens") — that framing applies globally (also to repositories via `DatabaseGateway`, also to jobs). | Grill evidence: `LinnworksHttpTransport`, `ShopwiredHttpTransport`, `HelpScoutHttpTransport`, `BingAdsTransport`, `GoogleAdsTransport`, `MixpanelHttpTransport`, `ReviewsIoHttpTransport` — every integration has one. |

**Commit 1 boundary check:** after commit 1, the only file changed is `app/Infrastructure/CLAUDE.md`. No scoped rules, no pointers, no deletions of migrated sections. The document is locally accurate and still loads globally. `make lint` and `make test` pass.

## Stage 2 — Proposed Rule Files

Commit 2 creates the five scoped-rule files, then replaces each migrated section in `app/Infrastructure/CLAUDE.md` with a one-line pointer. Layout modelled on the existing Eloquent/Presentation rules.

### 1. `.claude/rules/infrastructure-http-transports.md`

**Paths:**
```yaml
paths:
  - "app/Infrastructure/**/*Transport.php"
  - "!app/Infrastructure/**/Logging*Transport.php"
```

Negation excludes logging decorators (`LoggingLinnworksTransport`, `LoggingShopwiredTransport`, `LoggingMixpanelTransport`) — they implement the transport interface but only delegate to an inner transport; they don't translate exceptions themselves. The rule would misfire on them.

**Bullets:**
- DO wrap every HTTP/SDK call in try-catch and translate to a Domain API exception — nothing escapes the transport untranslated.
- DO log technical details (status code, response headers, truncated body) BEFORE translating — this context does not survive the Domain exception.
- DO reuse the existing translation matrix. See the catch-blocks in `LinnworksHttpTransport::get()` or `ShopwiredHttpTransport` for the current set of status → Domain-API-exception mappings; DO NOT invent new Domain API exception classes when the codebase already has one for the condition.
- DO NOT catch just to log and rethrow the raw SDK exception — translate, or don't catch.
- DO NOT return empty arrays or null to hide failures — throw.
- Canonical: `LinnworksHttpTransport`, `ShopwiredHttpTransport`.

**Match sample (7 files after negation):** `BingAdsTransport`, `GoogleAdsTransport`, `HelpScoutHttpTransport`, `LinnworksHttpTransport`, `MixpanelHttpTransport`, `ReviewsIoHttpTransport`, `ShopwiredHttpTransport`.

**Content ported from CLAUDE.md:** corrected versions of lines 15–22, 34–37 (post-Stage-1).

### 2. `.claude/rules/infrastructure-response-parsers.md`

**Paths:**
```yaml
paths:
  - "app/Infrastructure/**/*ResponseParserTrait.php"
```

**Bullets:**
- DO wrap `{DTO}::from($raw)` calls in a try-catch and translate Spatie DTO parse failures to `InvalidApiResponseException`.
- DO log at **CRITICAL** with the error message and `get_debug_type($response)` — do NOT log the raw response (PII + log-size risk).
- DO NOT retry on parse failure — it's a permanent API-contract violation until the DTO is updated.
- See `LinnworksResponseParserTrait::mapDtosFromArray()` for the exact Spatie exception class currently caught (the class name has changed across Spatie versions; keeping it out of this rule prevents drift).
- Canonical: `LinnworksResponseParserTrait`, `ShopwiredResponseParserTrait`.

**Match sample (2 files):** `Linnworks/Support/LinnworksResponseParserTrait.php`, `Shopwired/ShopwiredResponseParserTrait.php`.

**Content ported from CLAUDE.md:** corrected version of lines 24–32 (post-Stage-1).

### 3. `.claude/rules/infrastructure-client-factories.md`

**Paths:**
```yaml
paths:
  - "app/Infrastructure/**/*ClientFactory.php"
```

**Bullets:**
- DO throw `App\Domain\Exceptions\InvalidConfigurationException($envVar)` when reading a required Laravel config value that is missing, empty, or of the wrong type. **Why:** config gaps are bootstrap-time programming mistakes, not runtime conditions; using a domain exception keeps them grouped with other configuration failures for monitoring.
- DO validate config up-front in the factory and pass a typed config value object (e.g. `LinnworksConfig`) to the transport — the transport should never read config directly.
- Canonical: `LinnworksClientFactory::requireStringConfig()`, `LinnworksClientFactory::createConfig()`.

**Match sample (7 files):** `BingAdsClientFactory`, `HelpScoutClientFactory`, `LinnworksClientFactory`, `MixpanelClientFactory`, `GoogleAdsClientFactory`, `ReviewsIoClientFactory`, `ShopwiredClientFactory`.

**Content ported from CLAUDE.md:** corrected version of lines 39–41 (post-Stage-1), relocated from the implicit-client placement.

### 4. `.claude/rules/infrastructure-requests.md`

**Paths:**
```yaml
paths:
  - "app/Infrastructure/**/Requests/*.php"
```

**Bullets:**
- DO declare the class `final readonly` with a **private** constructor; expose a `public static` factory as the only entry point.
- DO name the factory after its input source — `fromCommand(DomainCommand)`, `fromDomain(DomainVO)`, or `fromResolved(...)` when the inputs are already-resolved IDs plus domain value objects. See neighbouring `Requests/*.php` for the conventional name in the integration.
- DO accept domain types (e.g. `Guid`, `Money`, enums, typed IDs) as factory parameters and extract scalars inside. Callers shouldn't re-derive wire values.
- DO return an API-shaped array from `toArray()` using the third party's key names (`StockItemId`, `SupplierID`) — not domain names.
- DO expose `public static buildBulkPayload(...)` for bulk endpoints; the parameter list mirrors the caller's bulk client method.
- DO NOT perform resolution (no SKU→ID lookups, no supplier-name→supplier-ID, no container calls). **Why:** orchestration is a UseCase responsibility — a Request is structural mapping only.
- DO NOT add business logic or conditional behaviour.
- EXCEPTION: pure wire-shape options objects with no domain types to resolve may skip the static factory — constructor alone is fine. Name them `*Options.php`. Canonical: `OrderStatusUpdateOptions`.
- Canonical: `AddInventoryItemRequest`, `UpdateStockSupplierStatRequest`.

**Match sample (8 files):** `AddInventoryItemRequest`, `ExtendedPropertyRequest`, `CreateStockSupplierStatRequest`, `UpdateStockSupplierStatRequest`, `ChangePurchaseOrderStatusRequest`, `CreatePurchaseOrderInitialRequest`, `GetPurchaseOrdersWithStockItemsRequest`, `OrderStatusUpdateOptions`.

**Content ported from CLAUDE.md:** corrected version of lines 51–104 (post-Stage-1).

### 5. `.claude/rules/infrastructure-response-dtos.md`

**Paths:**
```yaml
paths:
  - "app/Infrastructure/**/Responses/**/*Response.php"
```

**Bullets:**
- DO declare the class `final` (not `final readonly`) and extend `Spatie\LaravelData\Data`. The codebase convention is per-property `public readonly`, not class-level readonly.
- DO implement `App\Infrastructure\Contracts\DomainConvertibleInterface` with a `toDomain(): {DomainVO}` method — the `*ResponseParserTrait` methods depend on it (the parser calls `$dto->toDomain()` on every item).
- DO use class-level `#[MapInputName(...)]` with a mapper appropriate for the third party's wire shape — `Spatie\LaravelData\Mappers\SnakeCaseMapper` for snake_case APIs (Shopwired), custom mappers like `App\Infrastructure\Linnworks\Support\PascalCaseMapper` for PascalCase APIs (Linnworks). See neighbouring Response DTOs for the right choice.
- DO NOT reference this DTO from Domain code — Domain stays framework-independent. Response DTOs are Infrastructure-only; translate to Domain value objects via `toDomain()` before crossing the layer boundary.
- DO let parse failures propagate from `::from(...)` — the calling `*ResponseParserTrait` is where they become `InvalidApiResponseException`.
- Canonical: `Linnworks/Responses/OrderResponse`, `Shopwired/Responses/OrderResponse`.

**Match sample (35+ files):** all `HelpScout/Responses/*Response`, all `Linnworks/Responses/*Response` and `Linnworks/Responses/PurchaseOrder/*Response`, all `Shopwired/Responses/*Response`.

**Content ported from CLAUDE.md:** corrected version of lines 43–45 (post-Stage-1), plus the `DomainConvertibleInterface` requirement promoted from `Shopwired/CLAUDE.md` (universal pattern, not Shopwired-specific).

## CLAUDE.md Trimming (Stage 2, same commit as rule creation)

`app/Infrastructure/CLAUDE.md` post-migration keeps only architectural content plus pointers.

**Keep:**
- Eloquent Repositories pointer (line 5, already scoped).
- Exception Messages section (lines 7–9) — static-message rule applies to all Infrastructure exception throws.
- Exception Handling: Catch and Translate *opening paragraph* (the corrected version from Stage 1) — the architectural framing that Infrastructure is the layer where technical → business exception translation happens. Applies globally (transports, repositories catching `DatabaseGateway` exceptions, jobs catching `InvalidApiResponseException`).
- Domain-to-Model Mapping pointer (lines 47–49, already scoped).
- Golden Rule (line 106).

**Remove and replace with pointer (each pointer matches the Eloquent-pointer shape on line 5):**
- Lines 15–37 (Core Pattern + Nested Pattern + Critical Rules) → pointer: `> HTTP transport exception handling → .claude/rules/infrastructure-http-transports.md (auto-loads on *Transport.php)` + pointer: `> Nested DTO validation pattern → .claude/rules/infrastructure-response-parsers.md (auto-loads on *ResponseParserTrait.php)`
- Lines 39–41 (Configuration Validation) → pointer: `> Client factory config validation → .claude/rules/infrastructure-client-factories.md (auto-loads on *ClientFactory.php)`
- Lines 43–45 (Spatie LaravelData) → pointer: `> Response DTO conventions → .claude/rules/infrastructure-response-dtos.md (auto-loads on Responses/**/*Response.php)`
- Lines 51–104 (Client Contracts + Request Class Pattern + Rules) → pointer: `> Request class contract → .claude/rules/infrastructure-requests.md (auto-loads on Infrastructure/**/Requests/*.php)`

## Files Touched

**Commit 1 — Stage 1 drift corrections:**
- `app/Infrastructure/CLAUDE.md` — apply the 13 corrections in the drift table. No file creations, no pointer replacements yet.

**Commit 2 — Stage 2 extraction + trim:**
- **New (rules):**
  - `.claude/rules/infrastructure-http-transports.md`
  - `.claude/rules/infrastructure-response-parsers.md`
  - `.claude/rules/infrastructure-client-factories.md`
  - `.claude/rules/infrastructure-requests.md`
  - `.claude/rules/infrastructure-response-dtos.md`
- **Modified:**
  - `app/Infrastructure/CLAUDE.md` — trim the now-corrected sections to one-line pointers.
- **Untouched:**
  - All nested integration `CLAUDE.md` files under `app/Infrastructure/` (Shopwired, HelpScout, Mixpanel, Linnworks, Linnworks/Queries, BingAds, GoogleAds, ReviewsIo, Jobs, Shopwired/Models).
  - Any application code — this is pure documentation movement.

Implementation log at `.ai/implementation-logs/issue-647-infrastructure-rules-migration.md`, following the template in `.ai/implementation-logs/CLAUDE.md`. Use the "Deviations from Plan" section to record any mid-implementation discoveries; use the "Drift Corrections" sub-section under Decision Log for stage-1 audit trail.

## Rollout

Single PR, two commits (in order):

1. **Commit 1 — `docs(infrastructure): correct drifted conventions in CLAUDE.md`**  
   Applies the 13-item drift catalog to `app/Infrastructure/CLAUDE.md`. Scoped rules do not yet exist. `make lint` + `make test` pass. Reviewer can audit each correction against the canonical code.

2. **Commit 2 — `chore(claude): scope Infrastructure layer conventions to path-scoped .claude/rules files`**  
   Creates the 5 scoped rule files and trims the corrected sections in `app/Infrastructure/CLAUDE.md` to one-line pointers. `make lint` + `make test` pass.

No pilot — the `paths:` loading mechanism was already validated by the Eloquent and Presentation migrations.

## Verification

1. **After commit 1:** spot-check each of the 13 corrections against the canonical code cited in the evidence column. Every correction should be visibly justified by a grep-able reference.
2. **After commit 2:** open one file matching each new rule's glob in a fresh session; confirm the rule loads:
   - `app/Infrastructure/Linnworks/LinnworksHttpTransport.php` → `infrastructure-http-transports.md`
   - `app/Infrastructure/Linnworks/LoggingLinnworksTransport.php` → http-transports rule should **NOT** load (negation test)
   - `app/Infrastructure/Linnworks/Support/LinnworksResponseParserTrait.php` → `infrastructure-response-parsers.md`
   - `app/Infrastructure/Linnworks/LinnworksClientFactory.php` → `infrastructure-client-factories.md`
   - `app/Infrastructure/Linnworks/Requests/AddInventoryItemRequest.php` → `infrastructure-requests.md`
   - `app/Infrastructure/Linnworks/Responses/OrderResponse.php` → `infrastructure-response-dtos.md`
3. Open `app/Infrastructure/Repositories/AbstractEloquentRepository.php` — only `eloquent-repositories.md` should load; none of the five new rules.
4. `make lint` and `make test` pass after both commits.
5. Apply the Trimming Test from `.claude/rules/CLAUDE.md` to each bullet in each new file: remove the bullet, confirm Claude would still write compliant code from the surrounding code + canonical-class pointer alone. If yes, drop the bullet before committing.

## Out of Scope

- New conventions not already expressed in `app/Infrastructure/CLAUDE.md` (e.g. HMAC / webhook signature handling, transport retry strategies, session-manager conventions) — explicitly excluded by the issue.
- Nested integration `CLAUDE.md` files (Shopwired/, HelpScout/, etc.) — already effectively scoped by location; migration deferred by the issue.
  - EXCEPTION already agreed: the `DomainConvertibleInterface` requirement is promoted from `Shopwired/CLAUDE.md` into `infrastructure-response-dtos.md` because it's a universal Infrastructure-wide contract, not Shopwired-specific, and the parser traits break silently without it.
- Repository / database conventions — already covered by the Eloquent migration.
- Exception hierarchy restructuring or new exception types.
- Behaviour changes to `*ResponseParserTrait` logging (e.g. optional raw-response logging under a debug flag). Considered during the grill; parked for a separate issue.

## Grill Notes (for context)

The grill session (via `/grill-me`) revealed:

- The original 3-file plan targeted `*Client.php`, but the catch-and-translate pattern actually lives in `*Transport.php` and `*ResponseParserTrait.php`. Grill Q1 re-scoped the files.
- Configuration validation uses `InvalidConfigurationException`, not `RuntimeException`. Grill Q2 corrected it and added `infrastructure-client-factories.md`.
- The source `CLAUDE.md` has systematic drift from canonical code (class names, method names, logging content, mapping table completeness). Grill Q3 established the two-stage "correct first, extract second" process. Grill Q4 cataloged 13 drifts.
- Grill Q4 response reframed the catalog: rules that duplicate code contents (tables, exception class names, signature examples) will rot. The design principle "rules point at canonical code, not inventories" now shapes every bullet.
- Grill Q5 excluded `Logging*Transport.php` from the http-transports glob (delegator decorators don't translate) and promoted `DomainConvertibleInterface` from the nested Shopwired CLAUDE.md into the global rule.
- Grill Q6 confirmed two-commit rollout for reviewer clarity: commit 1 surfaces the drift corrections as a reviewable diff in isolation; commit 2 is the pure re-homing move.
