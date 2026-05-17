# COR-147 — Quote Conversion Pipeline (POST /conversions/quote)

## Context

Second vertical slice of the offline conversion tracking system (parent: COR-143). Mirrors the lead pipeline (COR-146) but adds:

1. **Sequential enforcement** — a QuoteIssued action cannot exist without a Completed LeadReceived action on the same submission
2. **Monetary value** — GBP ex-VAT sent as the Google Ads conversion value
3. **Explicit timestamp** — `converted_at` from the request (staff picks the date, not derived from submission)

The Google Ads client, `ClickConversionData` VO, `ActionType::QuoteIssued`, and `ConversionType::QuoteIssued` all already exist from COR-136/COR-144. This PR wires up the pipeline only.

---

## Implementation Order

### Step 1 — Application Contracts (modify 2 files)

**`app/Application/Contracts/ContactSubmission/ContactSubmissionActionRepositoryInterface.php`**
- Add `hasCompletedAction(string $submissionId, ActionType $actionType): bool`
- `@throws ExternalServiceUnavailableException` (DB query)

**`app/Application/Contracts/Conversion/ConversionDispatcherInterface.php`**
- Add `use App\Application\Conversion\Commands\QuoteConversionCommand`
- Add `dispatchQuoteConversion(QuoteConversionCommand $command): void`

### Step 2 — Application Command (new file)

**`app/Application/Conversion/Commands/QuoteConversionCommand.php`** — new, mirrors `LeadConversionCommand`
```
final readonly class QuoteConversionCommand
    Guid $submissionId
    Guid $actionId
    Money $value          // Money::exclusive() — GBP ex-VAT
    DateTimeImmutable $convertedAt
```

### Step 3 — Application Use Cases (3 new files)

**`app/Application/Conversion/UseCases/SubmitQuoteConversionUseCase.php`** — sync entry point
- Dependencies: `ContactSubmissionRepositoryInterface`, `ContactSubmissionActionRepositoryInterface`, `ConversionDispatcherInterface`, `LoggerInterface`
- `execute(string $submissionId, float $value, string $convertedAt): void`
- Steps:
  1. Log entry
  2. Load submission via `findById($submissionId)` → throws `RecordNotFoundException` (404)
  3. Validate gclid present → extract `ensureGclidPresent()` private helper → throws `InsufficientDataException` (422)
  4. Validate sequential: extract `ensureLeadCompleted()` private helper that calls `hasCompletedAction($submissionId, ActionType::LeadReceived)` → throws `InsufficientDataException` (422) if false
  5. Create action: `actionRepository->create($submissionId, ActionType::QuoteIssued)` → throws `DuplicateRecordException` (409) if exists
  6. Build command with `Guid::fromTrusted()`, `Money::exclusive($value)`, `new DateTimeImmutable($convertedAt)`
  7. Dispatch via `dispatcher->dispatchQuoteConversion($command)`
  8. Log completion

**Method-length note**: extracting `ensureGclidPresent()` and `ensureLeadCompleted()` keeps `execute()` under the `alz.excessiveMethodLength` 20-line ceiling (same as COR-146).

**`app/Application/Conversion/UseCases/ProcessQuoteConversionUseCase.php`** — async, called by job
- Dependencies: `ContactSubmissionRepositoryInterface`, `ContactSubmissionActionRepositoryInterface`, `GoogleAdsConversionClientInterface`, `LoggerInterface`
- `execute(string $submissionId, string $actionId, float $value, string $convertedAt): void`
- Steps:
  1. Log entry
  2. Idempotency guard: `isAlreadyTerminal()` → skip if completed/failed
  3. `incrementAttempts()`, `markProcessing()`
  4. Load submission for gclid + email
  5. Build `ClickConversionData` with:
     - `gclid` from submission
     - `email` from submission
     - `convertedAt` from `new DateTimeImmutable($convertedAt)` (**not** submission timestamp)
     - `value` from `Money::exclusive($value)` (**not** null like lead)
  6. `conversionClient->uploadConversion(ConversionType::QuoteIssued, $data)`
  7. `markCompleted($actionId, 'uploaded')`
  8. Log completion

**`app/Application/Conversion/UseCases/HandleQuoteConversionFailureUseCase.php`** — mirrors lead failure handler
- `execute(string $submissionId, string $actionId, string $exceptionMessage, int $attempts): void`
- Best-effort `markFailed()` with swallowed exception (same pattern as lead)

### Step 4 — Infrastructure (3 files: 1 new, 2 modified)

**`app/Infrastructure/Ingest/ContactSubmission/Repositories/EloquentContactSubmissionActionRepository.php`** — modify
- Add `hasCompletedAction()`: query `where(contact_submission_id, submissionId)->where(action_type, actionType)->where(status, Completed)->exists()` via `$this->gateway->query()`

**`app/Infrastructure/Conversion/Dispatchers/QueuedConversionDispatcher.php`** — modify
- Import `QuoteConversionCommand`, `ProcessQuoteConversionJob`
- Implement `dispatchQuoteConversion()`:
  ```
  ProcessQuoteConversionJob::dispatch(
      $command->submissionId->value,
      $command->actionId->value,
      $command->value->toNet(),       // float — Money::exclusive toNet = raw amount
      $command->convertedAt->format(DateTimeInterface::ATOM),
  )
  ```

**`app/Infrastructure/Jobs/Conversion/ProcessQuoteConversionJob.php`** — new, mirrors `ProcessLeadConversionJob`
- `ShouldBeUnique`, `ShouldQueue`
- Constructor: `string $submissionId, string $actionId, float $value, string $convertedAt`
- `onQueue(QueueName::Default->value)`
- `uniqueId()` → `$this->submissionId`
- Middleware: `ServiceCircuitBreaker::googleAds()`, `HandleApiExceptions`
- Same retry config as lead: tries=5, maxExceptions=5, backoff=[60,300,3600,43200], retryUntil=14hrs
- `handle(ProcessQuoteConversionUseCase $useCase)`: try/catch `InsufficientDataException|MalformedStoredDataException` → `$this->fail($e)`
- `failed()`: delegate to `HandleQuoteConversionFailureUseCase`

### Step 5 — Presentation (2 new files, 1 modified)

**`app/Presentation/Http/Api/DTOs/QuoteConversionRequestDTO.php`** — new
```php
final class QuoteConversionRequestDTO extends Data
    #[Required, Uuid, MapInputName('submission_id')]
    public readonly string $submissionId

    #[Required, Numeric, Min(0.01)]
    public readonly float $value

    #[Required, StringType, Date, BeforeOrEqual('now'), MapInputName('converted_at')]
    public readonly string $convertedAt
```
Note: `Spatie\LaravelData\Attributes\Validation\Date` is the established codebase pattern (`ContextSectionRequestDTO`). The DTO holds the wire-format string; the use case constructs `DateTimeImmutable` from it.

**`app/Presentation/Http/Api/Controllers/Conversion/QuoteConversionController.php`** — new
- Inject `SubmitQuoteConversionUseCase`
- `__invoke(QuoteConversionRequestDTO $request): JsonResponse`
- Passes `$request->submissionId`, `$request->value`, `$request->convertedAt`
- Returns 202 `{ "message": "Quote conversion queued" }`
- `@throws` mirrors lead + adds nothing new (same exception surface)

**`routes/api.php`** — modify
- Add below the lead route: `Route::post('conversions/quote', QuoteConversionController::class);`

---

## Design Decisions

| Decision | Rationale |
|----------|-----------|
| Use `InsufficientDataException` for both gclid and sequential checks | Both map to 422 via `InternalApiExceptionMapper`. The issue spec says 400/422 but the codebase consistently maps `InsufficientDataException` → 422. Following actual implementation over spec text. |
| Value flows through job args, not stored in actions table | The monetary value is a conversion property for Google Ads upload, not an action property. No schema migration needed. |
| `ProcessQuoteConversionUseCase` uses `convertedAt` from command, not from submission | Lead uses `submission.submittedAt` (timestamp when the form was filled). Quote uses a staff-provided date (quote may have been issued days before marking it). |
| `Money::exclusive($value).toNet()` for dispatcher serialisation | `toNet()` on an exclusive Money returns the raw amount unchanged. Reconstructed as `Money::exclusive($value)` in the ProcessUseCase. |
| Same retry config as lead job | No reason to differentiate — same Google Ads upload, same failure modes. |
| Primitives in job constructor + Process use case signature | Jobs serialise to queue — domain types can't survive serialisation. Use case reconstructs `Money`/`DateTimeImmutable` from the primitives. |
| No `toCommand()` on the request DTO | The command also needs `actionId` which is only known after the DB insert. Controller passes individual scalars to the use case; use case builds the command internally. Matches lead pattern. |
| `ShouldBeUnique::uniqueId()` reuses `submissionId` | Safe: Laravel's unique-job cache key includes the job class name, so `ProcessLeadConversionJob` and `ProcessQuoteConversionJob` for the same submission never collide on the same lock. |
| Sequential validation race (hasCompletedAction → create) is acceptable | Worst-case lose-the-race outcome is a duplicate quote action insert, which the unique constraint `idx_csa_unique_action(submission_id, action_type)` blocks → 409. No quote action ever gets created without a completed lead. |

---

## Files Summary

| Action | File | Why |
|--------|------|-----|
| **New** | `app/Application/Conversion/Commands/QuoteConversionCommand.php` | Dispatch payload with domain types |
| **New** | `app/Application/Conversion/UseCases/SubmitQuoteConversionUseCase.php` | Sync validation + dispatch |
| **New** | `app/Application/Conversion/UseCases/ProcessQuoteConversionUseCase.php` | Async upload + status tracking |
| **New** | `app/Application/Conversion/UseCases/HandleQuoteConversionFailureUseCase.php` | Best-effort failure marking |
| **New** | `app/Infrastructure/Jobs/Conversion/ProcessQuoteConversionJob.php` | Queue delivery mechanism |
| **New** | `app/Presentation/Http/Api/DTOs/QuoteConversionRequestDTO.php` | Wire format validation |
| **New** | `app/Presentation/Http/Api/Controllers/Conversion/QuoteConversionController.php` | HTTP entry point |
| **Modify** | `app/Application/Contracts/ContactSubmission/ContactSubmissionActionRepositoryInterface.php` | Add `hasCompletedAction()` |
| **Modify** | `app/Application/Contracts/Conversion/ConversionDispatcherInterface.php` | Add `dispatchQuoteConversion()` |
| **Modify** | `app/Infrastructure/Ingest/ContactSubmission/Repositories/EloquentContactSubmissionActionRepository.php` | Implement `hasCompletedAction()` |
| **Modify** | `app/Infrastructure/Conversion/Dispatchers/QueuedConversionDispatcher.php` | Implement `dispatchQuoteConversion()` |
| **Modify** | `routes/api.php` | Register quote route |

**No migration needed** — `ActionType::QuoteIssued` + `ConversionType::QuoteIssued` + actions table schema all already exist.

---

## Verification

1. `make lint` — all 5 linters pass (Pint, PHPStan, PHPArkitect, Deptrac, TLint)
2. `make test` — existing tests unbroken
3. `php artisan route:list --path=conversions` — both lead + quote routes visible with correct middleware stack
4. Tinker: `app(ConversionDispatcherInterface::class)` resolves `QueuedConversionDispatcher` with both `dispatchLeadConversion` + `dispatchQuoteConversion` methods
5. Tinker: `app(SubmitQuoteConversionUseCase::class)` resolves all dependencies
6. End-to-end POST deferred to user (mutates state + hits Google Ads)
7. Create `.ai/implementation-logs/cor-147-quote-conversion-pipeline.md` per `.ai/implementation-logs/CLAUDE.md` convention — captures decision log + PR notes draft
