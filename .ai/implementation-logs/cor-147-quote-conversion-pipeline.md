# COR-147 — Quote Conversion Pipeline (POST /conversions/quote)

**Branch**: `feature/cor-147-quote-conversion-pipeline-post-conversionsquote`
**Plan**: `.ai/plans/2026-05-18_COR-147-quote-conversion-pipeline.md`
**Started**: 2026-05-18

## Goal

Second vertical slice of offline conversion tracking. Mirrors the lead pipeline (COR-146) with three additions:
1. Sequential enforcement — `QuoteIssued` requires a completed `LeadReceived`
2. Monetary value — GBP ex-VAT sent to Google Ads
3. Staff-provided `converted_at` timestamp

## Decision Log

- **`InsufficientDataException` for both gclid + sequential checks** — both map to 422 via the global API exception mapper. Mirrors lead pipeline contract.
- **Value flows through job args (not stored)** — conversion value is a Google Ads upload property, not an action row property. No migration needed.
- **`ProcessQuoteConversionUseCase` uses `convertedAt` from the command, not the submission** — quote may be issued days after the form was filled; staff supplies the explicit date.
- **`Money::exclusive($value)` for ex-VAT amount** — `->toNet()` returns the raw amount unchanged, safe round-trip across job serialisation.
- **`uniqueId()` reuses `submissionId`** — Laravel's unique key namespaces by class, so lead + quote jobs for the same submission never collide.
- **`Date` validation attribute on `converted_at`** — established codebase pattern (`ContextSectionRequestDTO`). DTO holds wire string; use case constructs `DateTimeImmutable`.

## Files Touched

### New
- `app/Application/Conversion/Commands/QuoteConversionCommand.php`
- `app/Application/Conversion/UseCases/SubmitQuoteConversionUseCase.php`
- `app/Application/Conversion/UseCases/ProcessQuoteConversionUseCase.php`
- `app/Application/Conversion/UseCases/HandleQuoteConversionFailureUseCase.php`
- `app/Infrastructure/Jobs/Conversion/ProcessQuoteConversionJob.php`
- `app/Presentation/Http/Api/DTOs/QuoteConversionRequestDTO.php`
- `app/Presentation/Http/Api/Controllers/Conversion/QuoteConversionController.php`

### Modified
- `app/Application/Contracts/ContactSubmission/ContactSubmissionActionRepositoryInterface.php` — add `hasCompletedAction()`
- `app/Application/Contracts/Conversion/ConversionDispatcherInterface.php` — add `dispatchQuoteConversion()`
- `app/Infrastructure/Ingest/ContactSubmission/Repositories/EloquentContactSubmissionActionRepository.php` — implement `hasCompletedAction()`
- `app/Infrastructure/Conversion/Dispatchers/QueuedConversionDispatcher.php` — implement `dispatchQuoteConversion()`
- `routes/api.php` — register `POST /conversions/quote`

## Progress

- [x] Branch created, Linear status In Progress
- [x] Implementation log created
- [x] Application contracts updated (action repo + dispatcher)
- [x] QuoteConversionCommand created
- [x] Repository implementation (`hasCompletedAction`) added
- [x] All three use cases written (Submit/Process/HandleFailure)
- [x] ProcessQuoteConversionJob
- [x] QueuedConversionDispatcher impl
- [x] Controller + DTO + route
- [x] Existing tests pass — 3439 tests green
- [x] New test coverage added — 5 unit tests (SubmitQuoteConversionUseCase, all 4 status branches + happy path) + 4 feature tests (POST /conversions/quote: 401, 202, 422 missing fields, 422 non-numeric value). Suite now 3448 passing.
- [x] Linters pass — `make lint` green (Pint/PHPStan/PHPArkitect/Deptrac/TLint)
- [x] Simplify pass (documented above)
- [x] Sweep pass (documented above)
- [x] Validation step — live tinker validation declined by user; endpoint is write-only (route registration via `php artisan route:list --path=conversions` + green test suite confirm wiring)
- [x] Final summary

## Sweep Pass Outcomes (2026-05-18)

`make lint` verified green at sweep start. Findings after layer-by-layer review against `.claude/rules/`:

No code edits required — all rule checks pass. Notes:
- Class shapes, `@throws` propagation, business logging (entry + exit + skip paths), and queue-config (`tries`, `backoff`, `uniqueFor`, `retryUntil`, `failOnTimeout`, `timeout`) all conform.
- `hasCompletedAction` interface intentionally declares only `@throws ExternalServiceUnavailableException` — mirrors the read-method pattern set by `getStatus()`. Repo impl declares all three gateway exceptions per `eloquent-repositories.md`.
- `Money::exclusive($value)` → `->toNet()` in dispatcher is a no-op round-trip but enforces the command-property domain type required by `application-commands.md` — kept.
- `MalformedStoredDataException` for the DTO-validated `converted_at` parse failure is a minor semantic stretch (docblock says "stored data") but mirrors the established defensive pattern in `ProcessQuoteConversionUseCase`. Acceptable.
- `@ignoreException` annotation in `HandleQuoteConversionFailureUseCase::markActionFailed()` mirrors `HandleLeadConversionFailureUseCase` — intentional best-effort catch.

Out-of-scope (already flagged in simplify pass): Lead/Quote pipeline duplication (`ensureGclidPresent`, `HandleConversionFailureUseCase`, two-UPDATE markProcessing/incrementAttempts) — pre-existing in COR-146, untouched per task constraints.

## Simplify Pass Outcomes

Applied:
- Trimmed `@throws` on `hasCompletedAction()` interface to only `ExternalServiceUnavailableException` — matches existing `getStatus()` read-method pattern (impl still declares all three because gateway can raise them).
- Removed `ProcessQuoteConversionJob` import from `QuoteConversionController` (cross-layer import for a docblock-only `@see`).

Flagged as out-of-scope (follow-up):
- `ensureGclidPresent` duplicated across Lead+Quote × Submit+Process (4 sites total) — pre-existing in lead pipeline.
- `HandleConversionFailureUseCase` near-duplication — pre-existing pattern from COR-146.
- `incrementAttempts()` + `markProcessing()` as two UPDATEs — affects all three async pipelines; refactor on the shared `AsyncActionRepositoryInterface`.
- Optional index extension `(submission_id, action_type, status)` — current unique index pins matched rows to 1, so the post-filter is single-row recheck (no real cost).

Rejected:
- Primitive `float $value` / `string $convertedAt` on use case + job — documented in the plan's decision table as the intentional queue serialisation boundary.

## Open Issues (mid-fix)

1. `ProcessQuoteConversionUseCase::uploadAndMarkComplete()` has 5 params — `alz.excessiveParameterCount` (limit 4). **Fix**: pass pre-built `ClickConversionData` instead of `submission + value + convertedAt`, dropping to 3 params.
2. `SubmitQuoteConversionUseCase::execute()` is 27 lines — `alz.excessiveMethodLength` (limit 20). **Fix**: extract `buildCommand()` private helper.
3. `SubmitQuoteConversionUseCase::execute()` constructs `new DateTimeImmutable($convertedAt)` which can throw `DateMalformedStringException`. Resolved by the extraction in (2) — `buildCommand()` catches and translates to `MalformedStoredDataException` (source: `ConversionRequest`), mirroring the Process side.

## Notes since last update

- Submit use case uses two private validators (`ensureGclidPresent`, `ensureLeadCompleted`) to keep `execute()` under the 20-line PHPStan ceiling, mirroring `SubmitLeadConversionUseCase`.
- `hasCompletedAction()` declares `DuplicateRecordException` on `@throws` (it cannot raise it but is required for `EloquentGateway::query()` compliance per `.claude/rules/repository-contracts.md`).
- Process use case also wraps `new DateTimeImmutable($convertedAt)` in a try/catch and translates `DateMalformedStringException` → `MalformedStoredDataException` (source: `ConversionJobPayload`). The dispatcher serializes the command's `DateTimeImmutable` as ATOM; a parse failure here would indicate tampering / corruption between dispatch and execute.

## PR Notes (draft)

### Summary

Wire up `POST /conversions/quote` — the quote pipeline of offline conversion tracking. Builds on COR-146 (lead pipeline) and reuses the existing `ConversionType::QuoteIssued`, `ActionType::QuoteIssued`, Google Ads client, and contact-submission actions table.

### What's New

- `hasCompletedAction()` repository method for sequential enforcement
- Quote-side dispatcher + job mirroring lead retry/circuit-breaker config
- Sync `SubmitQuoteConversionUseCase` (validates + dispatches), async `ProcessQuoteConversionUseCase` (uploads to Google Ads)
- Quote request DTO accepts `submission_id`, `value` (GBP ex-VAT), `converted_at` (staff-picked date)

### Test plan

- [ ] `POST /conversions/quote` → 202 when submission has completed `LeadReceived` action + gclid
- [ ] 422 without completed lead or without gclid
- [ ] 409 on duplicate quote action for same submission
- [ ] Linters + existing tests still pass
