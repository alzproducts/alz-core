# COR-146 — Lead Conversion Pipeline (POST /conversions/lead)

**Linear:** [COR-146](https://linear.app/alzproducts/issue/COR-146)
**Branch:** `feature/cor-146-lead-conversion-pipeline-post-conversionslead`
**Plan:** `.ai/plans/2026-05-17_COR-146-lead-conversion-pipeline.md`
**Parent:** COR-143 (Offline conversion tracking: contact submission → Google Ads upload)

## Summary

First vertical slice of the offline conversion tracking system. New `POST /conversions/lead` endpoint accepts a `submission_id` UUID, validates a gclid exists on the submission, creates a `LeadReceived` action, then dispatches an async job that uploads the conversion to Google Ads via the existing `GoogleAdsConversionClientInterface`.

## Pipeline

```
POST /conversions/lead {submission_id}
  → LeadConversionController
    → SubmitLeadConversionUseCase (sync: validate, create action, dispatch)
      → ProcessLeadConversionJob (ShouldBeUnique, circuit breaker, retry)
        → ProcessLeadConversionUseCase (async: build VO, upload, mark status)
```

## Decision Log

| Decision | Rationale |
|----------|-----------|
| Two use cases (Submit + Process) | Submit runs sync (validates, creates action, dispatches), Process runs in job (uploads, marks status). Matches established `ContactSubmission` pattern. |
| `InsufficientDataException` → HTTP 400 | Request body valid, but referenced submission lacks gclid (precondition missing). Not 422 — that is for field validation. |
| `markCompleted(actionId, 'uploaded')` | Google Ads doesn't return a receipt ID. Sentinel value signals success without misrepresenting payload. |
| Dispatcher in `Infrastructure/Conversion/` | Action types are platform-agnostic (future Bing fan-out). Dispatcher is conversion-domain, not Google-specific. |
| Separate `ConversionServiceProvider` | Conversion is its own bounded context; binding lives with the domain it serves, not with the underlying ad platform. |
| `LeadConversionCommand` DTO | Type safety over raw strings; dispatcher signature self-documenting. |
| Empty-string gclid treated as null | Defensive against form data quirks — same precondition failure either way. |

### Code Review Polish (post-initial-commit, 2026-05-17)

| Decision | Rationale |
|----------|-----------|
| `LeadConversionCommand` uses `Guid` for both IDs (not `string`) | Domain CLAUDE.md type table mandates `Guid` for UUIDs in new code; cascade is `Guid::fromTrusted()` in the use case + `->value` extraction in the dispatcher. |
| Drop `submittedAt ?? now()` fallback — throw `InsufficientDataException` instead | Reverses the Step 3 open-decision compromise. Mapper always sets `submittedAt` from `created_at`, so the fallback was dead code that would silently send Google Ads a wrong conversion timestamp. Job catches `InsufficientDataException` → fails immediately. |
| Open + close `info` logs on both UseCase `execute()` methods | Without an entry log, a silent early return (terminal idempotency guard) is indistinguishable from the use case never being invoked. |
| Extract `ensureGclidPresent()` in `SubmitLeadConversionUseCase` | Adding the opening log pushed `execute()` to 24 lines; `alz.excessiveMethodLength` (custom PHPStan rule) fires at 20. Pre-flight gclid validation is a coherent operation worth its own named method. |
| Document why `markActionFailed()` swallows exceptions | If Laravel's `failed()` callback throws, the new exception masks the *original* one in `failed_jobs` and Sentry, hiding the actual root cause. The catch is intentional — `failed()` must always complete cleanly. |
| Two new scoped rules written | `application-use-cases.md` § Logging (opening + closing info logs); `application-commands.md` § Property Types (no primitives — use `Guid`/`IntId`/`Money`, update callers/callees). Both prevent recurrence of mistakes caught in this review. |

## Progress

- [x] Step 1 — Fetch Linear issue
- [x] Step 2 — Branch + log
- [x] Step 3 — Implement (all 10 new files written, 4 files modified, `php -l` clean)
- [x] Step 4 — Existing tests (3413 tests pass; 12 pre-existing notices, 1 risky — none from my changes)
- [x] Step 5 — Lint (all linters pass after extracting two methods to satisfy `alz.excessiveMethodLength`)
- [x] Step 6 — Progress summary
- [x] Step 7 — Simplify (applied: `COMPLETION_RECEIPT` const, trimmed controller class-level `@throws`, dropped narrative docblock)
- [x] Step 8 — Sweep (no fixes needed)
- [x] Step 9 — Validate (read-only: route registered, full DI graph resolves; end-to-end POST left to user — mutates state and hits Google Ads)
- [x] Step 10 — Final summary

## Validation Output (Step 9)

```
$ php artisan route:list --path=conversions --json
POST api/conversions/lead → LeadConversionController
  middleware: api, ValidateSupabaseJwtMiddleware, EnsureUserApprovedMiddleware,
              ThrottleRequests:api, SentryUserContextMiddleware

$ tinker: app(ConversionDispatcherInterface::class)
  → App\Infrastructure\Conversion\Dispatchers\QueuedConversionDispatcher ✓

$ tinker: app(SubmitLeadConversionUseCase::class)
  → resolved with EloquentContactSubmissionRepository,
    EloquentContactSubmissionActionRepository,
    QueuedConversionDispatcher, LogManager ✓
```

## PR Notes

### What
First vertical slice of the offline conversion tracking system: `POST /api/conversions/lead` flags a contact submission as a qualified lead and asynchronously uploads the conversion to Google Ads via the existing `GoogleAdsConversionClientInterface`.

### Why
The submission's `gclid` feeds Google's bidding algorithms so it can optimise for the B2B leads that actually convert into business, not just any contact-form fill.

### Key Decisions
- **Two-use-case split (Submit + Process)** mirrors the established `ContactSubmission` pipeline — synchronous validation/dispatch + async upload/status update.
- **`InsufficientDataException` → HTTP 400** (not 422): the request body is valid; the referenced submission lacks a precondition (gclid).
- **`COMPLETION_RECEIPT = 'uploaded'` const** documents Google Ads' missing receipt ID without an ad-hoc string.
- **Dispatcher under `Infrastructure/Conversion/`, not `Infrastructure/GoogleAds/`** — action types are platform-agnostic; future Bing fan-out goes here too.
- **New `ConversionServiceProvider`** (deferred) — conversion is its own bounded context, not coupled to any single ad platform.
- **Empty-string gclid treated as null** — defensive against form-data quirks.

### Post-Review Polish
- `LeadConversionCommand` uses `Guid` VOs (not raw strings) — applies the domain type table from `app/Domain/CLAUDE.md` to new code.
- Dropped `submittedAt ?? now()` fallback in favour of `InsufficientDataException` — the mapper always sets the timestamp from `created_at`, so the fallback was a silent data lie.
- Opening + closing `info` logs on both `execute()` methods so silent early returns are distinguishable from never-invoked.
- Two new scoped rules added — UseCase logging convention and Command property-type convention — to prevent recurrence.

### Testing
- `make test`: 3413 tests pass (12 pre-existing notices, 1 risky — none from this slice).
- `make lint`: Pint ✓, PHPStan 0 errors, PHPArkitect 0 violations, Deptrac 0 violations, TLint LGTM.
- Read-only DI validation via `tinker`: route registered, full dependency graph resolves.
- End-to-end POST left to the reviewer — it mutates state and triggers a live Google Ads upload.

## Sweep Findings (Step 8)

Comprehensive layer-by-layer review against `.claude/rules/` and `*/CLAUDE.md`:

- **Presentation** — `LeadConversionController` matches canonical controller shape (final readonly, `@throws` listed, no try/catch, JsonResponse). `LeadConversionRequestDTO` matches request DTO rules (final, attribute validation, `MapInputName`).
- **Application** — Use cases follow the SubmitContactSubmission / ProcessContactSubmission pattern closely. `@throws` propagation verified through repository contracts and `GoogleAdsConversionClientInterface`. Logging at appropriate `info`/`error`/`critical` levels with snake_case keys. Idempotency via `isAlreadyTerminal()` private helper. Static `COMPLETION_RECEIPT` constant explained via class doc-comment.
- **Infrastructure** — `ProcessLeadConversionJob` is a faithful mirror of `ProcessContactSubmissionJob` (same `$tries`, `$backoff`, `failOnTimeout`, `retryUntil`, ShouldBeUnique). Job logging is minimal (delegated to use cases per Jobs `CLAUDE.md`). `QueuedConversionDispatcher` is thin — pure translation. `ServiceCircuitBreaker::googleAds()` mirrors existing per-service factories.
- **Domain mapping** — No new domain classes; reuses existing `ClickConversionData`, `ConversionType`, `ActionType::LeadReceived`, `InsufficientDataException`.
- **General** — `bootstrap/providers.php` keeps alphabetical order. `ConversionServiceProvider` is `DeferrableProvider` and binds via `singleton()` — safe because `QueuedConversionDispatcher` is stateless (no lazy mutable state). `InternalApiExceptionMapper` change for `InsufficientDataException` → 400 matches plan's stated intent (request body valid, precondition missing).

**Issues found:** none requiring fixes — the slice is internally consistent and matches the established patterns from `ContactSubmission`. No `@phpstan-ignore`, no baseline additions, no nullable defaults that should be required.

## Files Created

1. `app/Application/Conversion/Commands/LeadConversionCommand.php`
2. `app/Application/Contracts/Conversion/ConversionDispatcherInterface.php`
3. `app/Application/Conversion/UseCases/SubmitLeadConversionUseCase.php`
4. `app/Application/Conversion/UseCases/ProcessLeadConversionUseCase.php`
5. `app/Application/Conversion/UseCases/HandleLeadConversionFailureUseCase.php`
6. `app/Infrastructure/Jobs/Conversion/ProcessLeadConversionJob.php`
7. `app/Infrastructure/Conversion/Dispatchers/QueuedConversionDispatcher.php`
8. `app/Presentation/Http/Api/DTOs/LeadConversionRequestDTO.php`
9. `app/Presentation/Http/Api/Controllers/Conversion/LeadConversionController.php`
10. `app/Providers/ConversionServiceProvider.php`

## Files Modified

- `app/Infrastructure/Jobs/Middleware/ServiceCircuitBreaker.php` — added `googleAds()` static factory
- `app/Presentation/Http/Api/InternalApiExceptionMapper.php` — added `InsufficientDataException` import; mapped to HTTP 400 in `domainStatusCode()`; added to `isValidationException()` so error type is `ValidationError`
- `routes/api.php` — imported `LeadConversionController`; added `Route::post('conversions/lead', LeadConversionController::class)` to Consumer API group
- `bootstrap/providers.php` — imported and registered `ConversionServiceProvider`

## Step 3 Notes

- All 10 new files pass `php -l` syntax checks.
- Resolved the open decision on `submittedAt`: defaulted to `now()->toDateTimeImmutable()` when null (defensive; submission VO allows null). Logged conversation already covered.
- DTO uses `final` (not `final readonly`) per `.claude/rules/presentation-request-dtos.md`.
- Controller uses `final readonly class` with per-method `@throws` per `.claude/rules/presentation-controllers.md`.
- Note: `isValidationException()` arm for `InsufficientDataException` gives error type `ValidationError` even though status is 400 (not 422) — semantically fine since this matches the plan's explicit `errorTypeFromException` mapping.

## Research Findings

Key existing code reviewed:
- `ServiceCircuitBreaker.php` — pattern: static factory wrapping `ThrottlesExceptions(10, 300)` with `->when(TransientApiFailure)`. Add `googleAds(): ThrottlesExceptions` after `reviewsio()`.
- `ProcessContactSubmissionJob.php` (`app/Infrastructure/Jobs/ContactForm/`) — the canonical mirror for `ProcessLeadConversionJob`. Uses traits (not `use Foo, Bar, Baz;` one-liner — three separate `use` statements). `retryUntil()` calls `\now()->addHours(14)->toDateTimeImmutable()`. Constructor sets queue via `QueueName::Default->value`. Implements `ShouldBeUnique, ShouldQueue`.
- `QueuedContactFormDispatcher` (in `Infrastructure/HelpScout/Dispatchers/`) — uses `#[Override]`, `final readonly class`. **Plan deviation:** plan called for `Infrastructure/Conversion/Dispatchers/` — this matches plan's stated rationale (platform-agnostic). Going with plan.
- `ProcessContactSubmissionUseCase` uses `getStatus() === ActionStatus::Completed` (not `?->isTerminal()`). Plan uses `?->isTerminal()` which is fine — `ActionStatus` already has that method. Use plan as-is.
- `HandleContactSubmissionFailureUseCase` — uses `// @ignoreException` comments on caught Throwables and logs `critical`. Will mirror in `HandleLeadConversionFailureUseCase`.
- `SubmitContactFormUseCase` uses `DatabaseGatewayInterface::transact()` for atomic save+action create. Plan says no transaction needed for lead (single insert) — submission already exists. Going with plan.
- `MarketingAttribution.php` — `gclid: ?string` matches plan; nullable check + empty-string defensive check from plan stands.
- `ContactSubmission` aggregate has `submittedAt: ?DateTimeImmutable` — needs null check or fallback. Plan passes it directly to `ClickConversionData`. ClickConversionData requires `DateTimeImmutable` non-null. Need to handle null `submittedAt` → fallback to `now()` or throw. **Decision needed at implementation time.**
- `routes/api.php` line 217 is end of "Consumer API Routes" group. Will add `Route::post('conversions/lead', LeadConversionController::class);` inside that group.
- `bootstrap/providers.php` — alphabetical-ish; insert `ConversionServiceProvider::class` after `ContactSubmissionServiceProvider` (line 47).
- `InternalApiExceptionMapper.php` — add `InsufficientDataException` arm in `domainStatusCode()` returning `HTTP_BAD_REQUEST`, and to `isValidationException()` so error type maps to `ValidationError`. Import the exception class.
- `ContactFormDispatcherInterface` is simple — two raw string args. Plan introduces `LeadConversionCommand` DTO for type safety. Keep DTO approach.

## Implementation Order (resumed)

Files to create (worktree path `/Users/tom/code/IdeaProjects/alz-core-two/`):

1. `app/Application/Conversion/Commands/LeadConversionCommand.php`
2. `app/Application/Contracts/Conversion/ConversionDispatcherInterface.php`
3. `app/Application/Conversion/UseCases/ProcessLeadConversionUseCase.php`
4. `app/Application/Conversion/UseCases/HandleLeadConversionFailureUseCase.php`
5. `app/Application/Conversion/UseCases/SubmitLeadConversionUseCase.php`
6. `app/Infrastructure/Jobs/Conversion/ProcessLeadConversionJob.php`
7. `app/Infrastructure/Conversion/Dispatchers/QueuedConversionDispatcher.php`
8. `app/Presentation/Http/Api/DTOs/LeadConversionRequestDTO.php`
9. `app/Presentation/Http/Api/Controllers/Conversion/LeadConversionController.php`
10. `app/Providers/ConversionServiceProvider.php`

Files to modify:
- `app/Infrastructure/Jobs/Middleware/ServiceCircuitBreaker.php` (+ `googleAds()`)
- `app/Presentation/Http/Api/InternalApiExceptionMapper.php` (+ `InsufficientDataException` → 400)
- `routes/api.php` (+ `Route::post('conversions/lead', ...)`)
- `bootstrap/providers.php` (+ `ConversionServiceProvider::class`)

## Open Decision

- `submittedAt` is `?DateTimeImmutable` on `ContactSubmission` aggregate but `ClickConversionData::convertedAt` is non-null. If null, fallback to `now()->toDateTimeImmutable()` (defensive). Logging the fallback so it's surfaced.

## PR Notes

_To be drafted after implementation._
