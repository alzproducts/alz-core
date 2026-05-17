# COR-146: Lead Conversion Pipeline (POST /conversions/lead)

## Context

Enable staff to mark qualified contact form submissions as leads, triggering an async Google Ads offline conversion upload. This is the first vertical slice of the offline conversion tracking system (parent: COR-143). The submission's gclid feeds Google's bidding algorithms to optimise for B2B leads that convert into real business.

**Branch:** `feature/cor-143c-lead-conversion`

---

## Pipeline Flow

```
POST /conversions/lead {submission_id}
  → LeadConversionController (validates UUID)
    → SubmitLeadConversionUseCase
      1. findById(submissionId) → 404 if missing
      2. Check gclid present → 400 if null
      3. Create action (LeadReceived, Pending) → 409 if duplicate
      4. Dispatch via ConversionDispatcherInterface
        → ProcessLeadConversionJob (unique per submission)
          → ProcessLeadConversionUseCase
            1. markProcessing + incrementAttempts
            2. Build ClickConversionData(gclid, email, submittedAt, null)
            3. uploadConversion(LeadReceived, data)
            4. markCompleted
```

---

## New Files (10)

### Presentation

| # | File | Purpose |
|---|------|---------|
| 1 | `app/Presentation/Http/Api/DTOs/LeadConversionRequestDTO.php` | Spatie Data: `submission_id` as required UUID |
| 2 | `app/Presentation/Http/Api/Controllers/Conversion/LeadConversionController.php` | Invocable, delegates to SubmitLeadConversionUseCase, returns 202 |

### Application

| # | File | Purpose |
|---|------|---------|
| 3 | `app/Application/Contracts/Conversion/ConversionDispatcherInterface.php` | Shared dispatcher (lead now, quote in COR-147) |
| 4 | `app/Application/Conversion/Commands/LeadConversionCommand.php` | Readonly DTO: submissionId + actionId |
| 5 | `app/Application/Conversion/UseCases/SubmitLeadConversionUseCase.php` | Synchronous: validate, create action, dispatch |
| 6 | `app/Application/Conversion/UseCases/ProcessLeadConversionUseCase.php` | Async: build ClickConversionData, upload, mark status |
| 7 | `app/Application/Conversion/UseCases/HandleLeadConversionFailureUseCase.php` | Job exhaustion: markFailed |

### Infrastructure

| # | File | Purpose |
|---|------|---------|
| 8 | `app/Infrastructure/Conversion/Dispatchers/QueuedConversionDispatcher.php` | Dispatches ProcessLeadConversionJob |
| 9 | `app/Infrastructure/Jobs/Conversion/ProcessLeadConversionJob.php` | ShouldBeUnique, HandleApiExceptions, circuit breaker |
| 10 | `app/Providers/ConversionServiceProvider.php` | Deferred provider binding `ConversionDispatcherInterface` → `QueuedConversionDispatcher` |

---

## Modified Files (4)

| File | Change |
|------|--------|
| `app/Infrastructure/Jobs/Middleware/ServiceCircuitBreaker.php` | Add `public static function googleAds(): ThrottlesExceptions` |
| `app/Presentation/Http/Api/InternalApiExceptionMapper.php` | Add `InsufficientDataException` → HTTP 400 mapping |
| `routes/api.php` | Add `Route::post('conversions/lead', ...)` in Consumer API group |
| `bootstrap/providers.php` | Register `ConversionServiceProvider::class` |

---

## Implementation Order

1. **ServiceCircuitBreaker** — add `googleAds()` static factory
2. **InternalApiExceptionMapper** — add `InsufficientDataException` → 400
3. **LeadConversionCommand** — simple readonly DTO
4. **ConversionDispatcherInterface** — define contract
5. **ProcessLeadConversionUseCase** — core logic (build data, upload, mark status)
6. **HandleLeadConversionFailureUseCase** — failure path
7. **SubmitLeadConversionUseCase** — API-facing orchestrator
8. **ProcessLeadConversionJob** — queue job wrapping ProcessLeadConversionUseCase
9. **QueuedConversionDispatcher** — implements interface, dispatches job
10. **LeadConversionRequestDTO** — request validation
11. **LeadConversionController** — HTTP endpoint
12. **routes/api.php** — register route
13. **ConversionServiceProvider** — bind ConversionDispatcherInterface
14. **bootstrap/providers.php** — register provider

---

## File Details

### 1. `LeadConversionRequestDTO`

```php
final class LeadConversionRequestDTO extends Data
{
    public function __construct(
        #[Required, Uuid]
        #[MapInputName('submission_id')]
        public readonly string $submissionId,
    ) {}
}
```

### 2. `LeadConversionController`

```php
final readonly class LeadConversionController
{
    public function __construct(
        private SubmitLeadConversionUseCase $useCase,
    ) {}

    public function __invoke(LeadConversionRequestDTO $request): JsonResponse
    {
        $this->useCase->execute($request->submissionId);

        return new JsonResponse(
            ['message' => 'Lead conversion queued'],
            Response::HTTP_ACCEPTED,
        );
    }
}
```

Exceptions bubble to `InternalApiExceptionMapper`:
- `RecordNotFoundException` → 404
- `InsufficientDataException` → 400 (new mapping)
- `DuplicateRecordException` → 409 (existing mapping)

### 3. `ConversionDispatcherInterface`

```php
interface ConversionDispatcherInterface
{
    public function dispatchLeadConversion(LeadConversionCommand $command): void;
}
```

### 4. `LeadConversionCommand`

```php
final readonly class LeadConversionCommand
{
    public function __construct(
        public string $submissionId,
        public string $actionId,
    ) {}
}
```

### 5. `SubmitLeadConversionUseCase`

Dependencies: `ContactSubmissionRepositoryInterface`, `ContactSubmissionActionRepositoryInterface`, `ConversionDispatcherInterface`, `LoggerInterface`

```php
public function execute(string $submissionId): void
{
    $submission = $this->submissionRepository->findById($submissionId);

    // Treat empty-string gclid the same as null — defensive against form data quirks
    if ($submission->attribution->gclid === null || $submission->attribution->gclid === '') {
        throw new InsufficientDataException('ContactSubmission', 'a gclid for conversion tracking');
    }

    $actionId = $this->actionRepository->create($submissionId, ActionType::LeadReceived);

    $this->dispatcher->dispatchLeadConversion(
        new LeadConversionCommand($submissionId, $actionId),
    );

    $this->logger->info('Lead conversion dispatched', [
        'submission_id' => $submissionId,
        'action_id' => $actionId,
    ]);
}
```

No transaction needed — single insert (action), submission already exists.

### 6. `ProcessLeadConversionUseCase`

Dependencies: `ContactSubmissionRepositoryInterface`, `ContactSubmissionActionRepositoryInterface`, `GoogleAdsConversionClientInterface`, `LoggerInterface`

```php
public function execute(string $submissionId, string $actionId): void
{
    $status = $this->actionRepository->getStatus($actionId);
    if ($status?->isTerminal()) {
        return; // idempotency guard
    }

    $this->actionRepository->incrementAttempts($actionId);
    $this->actionRepository->markProcessing($actionId);

    $submission = $this->submissionRepository->findById($submissionId);

    // Defensive: gclid invariant should hold (SubmitLeadConversionUseCase validated),
    // but ClickConversionData requires non-empty. Assert narrows ?string → string for PHPStan.
    Assert::notEmpty($submission->attribution->gclid, 'gclid missing at conversion time');

    $data = new ClickConversionData(
        gclid: $submission->attribution->gclid,
        email: $submission->form->email,
        convertedAt: $submission->submittedAt,
        value: null,
    );

    $this->conversionClient->uploadConversion(ConversionType::LeadReceived, $data);

    $this->actionRepository->markCompleted($actionId, 'uploaded');

    $this->logger->info('Lead conversion uploaded', [
        'submission_id' => $submissionId,
        'action_id' => $actionId,
    ]);
}
```

### 7. `HandleLeadConversionFailureUseCase`

Dependencies: `ContactSubmissionActionRepositoryInterface`, `LoggerInterface`

```php
public function execute(string $submissionId, string $actionId, string $exceptionMessage, int $attempts): void
{
    $this->logger->error('Lead conversion failed permanently', [
        'submission_id' => $submissionId,
        'action_id' => $actionId,
        'attempts' => $attempts,
    ]);

    try {
        $this->actionRepository->markFailed($actionId, $exceptionMessage);
    } catch (Throwable) {
        // best-effort — don't mask the original failure
    }
}
```

### 8. `ProcessLeadConversionJob`

Mirrors `ProcessContactSubmissionJob` pattern:

```php
final class ProcessLeadConversionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;
    public int $maxExceptions = 5;
    public bool $failOnTimeout = true;
    public int $timeout = 60;
    public array $backoff = [60, 300, 3600, 43200];
    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $submissionId,
        public readonly string $actionId,
    ) {
        $this->onQueue(QueueName::Default->value);
    }

    public function uniqueId(): string { return $this->submissionId; }

    public function middleware(): array
    {
        return [
            ServiceCircuitBreaker::googleAds(),
            new HandleApiExceptions(),
        ];
    }

    public function retryUntil(): DateTimeImmutable
    {
        return now()->addHours(14)->toDateTimeImmutable();
    }

    public function handle(ProcessLeadConversionUseCase $useCase): void
    {
        try {
            $useCase->execute($this->submissionId, $this->actionId);
        } catch (InsufficientDataException|MalformedStoredDataException $e) {
            $this->fail($e);
        }
    }

    public function failed(Throwable $exception): void
    {
        /** @var HandleLeadConversionFailureUseCase $useCase */
        $useCase = \app(HandleLeadConversionFailureUseCase::class);
        $useCase->execute(
            submissionId: $this->submissionId,
            actionId: $this->actionId,
            exceptionMessage: $exception->getMessage(),
            attempts: $this->attempts(),
        );
    }
}
```

### 9. `QueuedConversionDispatcher`

```php
final readonly class QueuedConversionDispatcher implements ConversionDispatcherInterface
{
    public function dispatchLeadConversion(LeadConversionCommand $command): void
    {
        ProcessLeadConversionJob::dispatch($command->submissionId, $command->actionId);
    }
}
```

---

## Modifications Detail

### ServiceCircuitBreaker

Add after `reviewsio()`:
```php
public static function googleAds(): ThrottlesExceptions
{
    return self::create('google-ads');
}
```

### InternalApiExceptionMapper

In `domainStatusCode()`, add before the 422 group:
```php
$e instanceof InsufficientDataException => Response::HTTP_BAD_REQUEST,
```

In `errorTypeFromException()`, add a mapping:
```php
$e instanceof InsufficientDataException => ApiErrorTypeEnum::ValidationError,
```

Add `use App\Domain\Exceptions\Data\InsufficientDataException;` import.

### routes/api.php

Inside the Consumer API group (after the ClickUp block ~line 206):
```php
// Conversion endpoints
Route::post('conversions/lead', LeadConversionController::class);
```

### ConversionServiceProvider (new)

```php
final class ConversionServiceProvider extends ServiceProvider implements DeferrableProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->singleton(
            ConversionDispatcherInterface::class,
            QueuedConversionDispatcher::class,
        );
    }

    /**
     * @return list<class-string>
     */
    #[Override]
    public function provides(): array
    {
        return [
            ConversionDispatcherInterface::class,
        ];
    }
}
```

### bootstrap/providers.php

Add `ConversionServiceProvider::class` to the providers array (alphabetical order suggests after `ContactSubmissionServiceProvider`).

---

## Key Decisions

| Decision | Rationale |
|----------|-----------|
| Two use cases (Submit + Process) | Follows established pattern: submit runs synchronously (validates, creates action, dispatches), process runs in job (uploads, marks status) |
| `InsufficientDataException` → 400 | Not 422 — the request body is valid, but the referenced submission lacks a precondition (gclid). Distinct from field validation. |
| `markCompleted(actionId, 'uploaded')` | Google Ads doesn't return a receipt ID. Sentinel value signals success. |
| Dispatcher in `Infrastructure/Conversion/` not `Infrastructure/GoogleAds/` | Action types are platform-agnostic (future Bing fan-out). Dispatcher is conversion-domain, not Google-specific. |
| New `ConversionServiceProvider` (not adding to GoogleAdsServiceProvider) | Conversion is its own bounded context; binding lives with the domain it serves, not with the underlying ad platform. Future-proof for multi-platform fan-out. |
| `LeadConversionCommand` DTO | Type safety over raw strings. Makes dispatcher signature self-documenting. |

---

## Verification

1. **Unit tests**: SubmitLeadConversionUseCase (happy path, no gclid, duplicate), ProcessLeadConversionUseCase (happy path, idempotency guard)
2. **Integration test**: HTTP POST with valid submission_id → 202, with no-gclid submission → 400, duplicate → 409, missing → 404
3. **Smoke test**: Dispatch job locally via tinker, check logs for upload attempt (will fail against real Google Ads without valid gclid, but verifies pipeline)
4. **Linting**: `make lint` passes (PHPStan, Arkitect, Deptrac layer checks)

---

## Reusable Existing Code

| What | Where | How Used |
|------|-------|----------|
| `ClickConversionData` VO | `app/Domain/Conversion/ValueObjects/` | Built in ProcessLeadConversionUseCase |
| `ConversionType::LeadReceived` | `app/Domain/Conversion/Enums/` | Passed to uploadConversion |
| `GoogleAdsConversionClientInterface` | `app/Application/Contracts/` | Called by ProcessLeadConversionUseCase |
| `ContactSubmissionRepositoryInterface` | `app/Application/Contracts/ContactSubmission/` | findById in both use cases |
| `ContactSubmissionActionRepositoryInterface` | `app/Application/Contracts/ContactSubmission/` | create/markProcessing/markCompleted/markFailed |
| `ActionType::LeadReceived` | `app/Domain/ContactSubmission/Enums/` | Passed to action create |
| `HandleApiExceptions` middleware | `app/Infrastructure/Jobs/Middleware/` | Job middleware |
| `InsufficientDataException` | `app/Domain/Exceptions/Data/` | Thrown when gclid is null |
| `InternalApiExceptionMapper` | `app/Presentation/Http/Api/` | Maps exceptions → HTTP status |
