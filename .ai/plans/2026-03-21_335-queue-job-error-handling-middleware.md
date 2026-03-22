#Plan: Laravel Jobs Guide — Code-Touching Refactor

## Context

The `.ai/docs/guides/guide-to-laravel-jobs-2026.md` guide recommends numerous improvements for queue job classes. Config/DevOps/Horizon changes are already done. This plan covers **code changes to job classes themselves**: extracting repeated error-handling boilerplate into middleware, adding proper Sentry integration via `Queue::failing`, and introducing per-service circuit breakers.

The codebase has **39 job classes** across 6 domains (Shopwired, Linnworks, Mixpanel, Inventory, Reviews.io, ContactForm). Most follow "Pattern A" — identical 3-catch blocks (TransientApiFailure/PermanentApiFailure/Throwable) that account for ~30 lines of boilerplate per job. All Pattern A jobs currently rethrow after `fail()` as a workaround to get Sentry to capture exceptions via `Worker::report()`.

**Key design change**: The guide and Laravel's own `ThrottlesExceptions` middleware use `fail()` + `return` (no rethrow). We adopt this pattern and replace the rethrow-for-Sentry workaround with a proper `Queue::failing` hook.

---

## Critical Review: What We're Doing and NOT Doing

### ACCEPTED

| # | Change | Value | Jobs Affected |
|---|--------|-------|---------------|
| 1 | `HandleApiExceptions` middleware | Eliminates ~30 lines/job of identical catch blocks | ~25 Pattern A jobs |
| 2 | `Queue::failing` → Sentry hook | Replaces rethrow-for-Sentry workaround with proper event-based capture | All jobs (no code changes) |
| 3 | `ThrottlesExceptions` per-service | Circuit breaker prevents hammering struggling APIs | ~30 jobs across 5 services |
| 4 | `AbstractSyncShopwiredEntityJob` simplification | Remove `withErrorHandling()`, use middleware instead | 1 abstract + 5 subclasses |
| 5 | Transient retry warning in middleware | Centralize the per-job warning log | ~25 Pattern A jobs |
| 6 | `$maxExceptions` standardization | Double `$tries`, add `$maxExceptions` at original value | All jobs |
| 7 | ThrottlesExceptions on Pattern B | Circuit breaking even with custom error handling | 3 Pattern B jobs |
| 8 | `$failOnTimeout = true` | Defense-in-depth: timeouts are already generous, so a timeout is genuinely abnormal | All jobs |
| 9 | `retryUntil()` | Time-based ceiling — safety net against runaway retries with doubled $tries + API retryAfter | All jobs |
| 10 | Centralized completion logging via `Queue::after` | Default "job completed" log for all jobs — per-job result logging becomes optional bonus | All jobs (no code changes) |

### REJECTED (with rationale)

| Change | Why Rejected |
|--------|-------------|
| Unified `Queueable` trait | Adds `SerializesModels` + `Batchable` unnecessarily; cosmetic only; guide says "no migration required" |
| `#[WithoutRelations]` | Not applicable — all constructors use DTOs/VOs, zero Eloquent models |
| `Skip` middleware (on existing jobs) | Most skip logic lives correctly in UseCases (depends on runtime data). No current jobs have clean pre-execution checks. **Document the pattern in Jobs CLAUDE.md** for future jobs that have dispatch-time preconditions. |

**$maxExceptions approach**: Balanced — double current `$tries` (e.g., 3→6, 2→4) and set `$maxExceptions` to the original value. Allows more release-based retries without drastic behavioral shift.

**ThrottlesExceptions params**: 10 failures / 5 minutes for all services uniformly.

**$failOnTimeout**: Timeouts are already set generously (90s default, 9000s low queue). A timeout at those thresholds is abnormal — hung connection, infinite loop, not "API was slow." Without it, a timed-out job with $tries=6 wastes 6×90s = 9 minutes. With it, fails immediately → Sentry → investigate.

**retryUntil() values** (time-based ceiling per queue tier):
- High queue (90s timeout): `now()->addHours(1)` — time-sensitive, don't retry too long
- Default queue (90s timeout): `now()->addHours(4)` — allows all retries with buffer
- Low queue (9000s timeout): `now()->addHours(24)` — bulk jobs need room to breathe

---

## Implementation Steps

### Step 1: Create `HandleApiExceptions` middleware + tests

**New file**: `app/Infrastructure/Jobs/Middleware/HandleApiExceptions.php`

Follows the same pattern as Laravel's `ThrottlesExceptions`: `fail()` + `return` for permanent failures (no rethrow). Only rethrows for TransientApiFailure without retryAfter (so the worker can apply `$backoff` array and increment `$maxExceptions` counter).

```php
/**
 * Centralised error handling for queue jobs calling external APIs.
 *
 * Follows Laravel's ThrottlesExceptions pattern: fail()+return for permanent
 * failures, release()+return for API-managed retries. Only rethrows when the
 * worker needs to manage backoff and $maxExceptions counting.
 *
 * Permanent failures are captured by Sentry via QueueObservabilityServiceProvider's
 * Queue::failing hook (since we don't rethrow, Worker::report() is not invoked).
 */
final class HandleApiExceptions
{
    /**
     * @param  object&\Illuminate\Queue\InteractsWithQueue  $job
     */
    public function handle(object $job, Closure $next): void
    {
        try {
            $next($job);
        } catch (TransientApiFailure $e) {
            Log::warning('Job transient failure, releasing for retry', [
                'job' => $job::class,
                'service' => $e->serviceName,
                'retry_after' => $e->retryAfter,
            ]);

            if ($e->retryAfter !== null) {
                $job->release($e->retryAfter);
                return; // API-managed retry timing
            }

            // No retryAfter: let worker handle $backoff + $maxExceptions counting
            throw $e;
        } catch (Throwable $e) {
            $job->fail($e);
            return; // Queue::failing → Sentry. No rethrow needed.
        }
    }
}
```

**Why `return` instead of `throw` for permanent failures?**
- Matches Laravel's `ThrottlesExceptions` pattern (`return $job->fail($throwable)`)
- `$job->fail()` already: marks as failed, deletes from queue, calls `failed()` callback, fires `JobFailed` event
- Sentry capture via `Queue::failing` hook (Step 2) replaces the rethrow-for-Sentry workaround
- The guide explicitly says: *"both $this->release() and $this->fail() do not halt code execution. You must return after calling them."*

**Why `throw` for TransientApiFailure without retryAfter?**
- Worker needs to apply `$backoff` array (managed in `Worker::handleJobException()`)
- Worker needs to increment `$maxExceptions` counter (also in `handleJobException()`)
- `ThrottlesExceptions` (outer middleware) needs to count this failure for circuit breaking

**New test**: `tests/Unit/Infrastructure/Jobs/Middleware/HandleApiExceptionsTest.php`
- Transient with retryAfter → release + return (no throw, verify no exception propagates)
- Transient without retryAfter → rethrow (verify exception propagates)
- PermanentApiFailure → fail() + return (no throw)
- Generic Throwable → fail() + return (no throw)
- Happy path (no exception) → passes through

### Step 2: Add `Queue::failing` Sentry hook

**Modified file**: `app/Providers/QueueObservabilityServiceProvider.php`

Add `Queue::failing` hook alongside existing `Queue::before`. This replaces the rethrow-for-Sentry workaround — since `HandleApiExceptions` uses `fail()` + `return` (no rethrow), exceptions no longer reach `Worker::report()`. The `Queue::failing` hook fires when `$job->fail()` dispatches the `JobFailed` event.

```php
use Illuminate\Queue\Events\JobFailed;
use Sentry\SentrySdk;

Queue::failing(static function (JobFailed $event): void {
    if (\class_exists(SentrySdk::class)) {
        \Sentry\captureException($event->exception);
    }
});
```

Uses `\Sentry\captureException()` (global function) for reliability — consistent with the existing `SentrySdk::class` check pattern in `AppServiceProvider`. The `SentryBeforeSendCallback` still applies, so throttled exceptions (`ExternalServiceUnavailableException`, etc.) are sampled at 10%.

Update the class docblock to explain the Sentry integration (remove the "intentionally omitted" comment).

Also add `Queue::after` for centralized completion logging:

```php
Queue::after(static function (JobProcessed $event): void {
    Log::info('Job completed', [
        'job' => $event->job->resolveName(),
        'queue' => $event->job->getQueue(),
        'connection' => $event->connectionName,
    ]);
});
```

This provides a default "completed" log for every job. Per-job logging with result data (fetched/saved/failed counts) in `handle()` is optional bonus context on top. New jobs don't need explicit completion logging — they get it for free.

### Step 3: Refactor `AbstractSyncShopwiredEntityJob` + 5 subclasses

**Modified files**:
- `app/Infrastructure/Jobs/Shopwired/AbstractSyncShopwiredEntityJob.php`
  - Remove `withErrorHandling()` method entirely
  - Add `middleware()` method returning `[new HandleApiExceptions()]`
  - Double `$tries` (3→6), keep `$maxExceptions = 3`
  - Keep: `uniqueId()`, `failed()`, abstract template methods
- `app/Infrastructure/Jobs/Shopwired/SyncShopwiredBrandJob.php`
- `app/Infrastructure/Jobs/Shopwired/SyncShopwiredCategoryJob.php`
- `app/Infrastructure/Jobs/Shopwired/SyncShopwiredCustomerJob.php`
- `app/Infrastructure/Jobs/Shopwired/SyncShopwiredOrderJob.php`
- `app/Infrastructure/Jobs/Shopwired/SyncShopwiredProductJob.php`

Subclass handle() before:
```php
public function handle(SyncBrandUseCase $useCase, LoggerInterface $logger): void
{
    $this->withErrorHandling($logger, function () use ($useCase): void {
        $useCase->execute($this->entityId);
    });
}
```

After:
```php
public function handle(SyncBrandUseCase $useCase, LoggerInterface $logger): void
{
    $useCase->execute($this->entityId);
    $logger->info('Brand sync complete', ['brand_id' => $this->entityId->value]);
}
```

### Step 4: Refactor Pattern A jobs (batch by domain)

For each Pattern A job, the transformation is:

**Before** (~50 lines):
```php
public function handle(UseCase $useCase, LoggerInterface $logger): void
{
    $logger->info('Job starting', [...context...]);
    try {
        $result = $useCase->execute(...);
        $logger->info('Job completed', [...result...]);
    } catch (TransientApiFailure $e) {
        $logger->warning('Service unavailable', [...]);
        if ($e->retryAfter !== null) { $this->release($e->retryAfter); }
        else { throw $e; }
    } catch (PermanentApiFailure $e) {
        $this->fail($e); throw $e;
    } catch (Throwable $e) {
        $this->fail($e); throw $e;
    }
}
```

**After** (~10 lines):
```php
public function handle(UseCase $useCase, LoggerInterface $logger): void
{
    $logger->info('Job starting', [...context...]);
    $result = $useCase->execute(...);
    $logger->info('Job completed', [...result...]);
}

public function middleware(): array
{
    return [new HandleApiExceptions()];
}
```

Also apply property changes during this step (not a separate pass):

**$maxExceptions** (double $tries, add $maxExceptions at original value):

| Current `$tries` | New `$tries` | `$maxExceptions` |
|-------------------|-------------|------------------|
| 2 | 4 | 2 |
| 3 | 6 | 3 |
| 5 | 10 | 5 |

**$failOnTimeout** — add to all jobs:
```php
public bool $failOnTimeout = true;
```

**retryUntil()** — add to all jobs, based on queue tier:
```php
// Default/High queue jobs (QueueName::Default, QueueName::High)
public function retryUntil(): DateTime
{
    return now()->addHours(4);
}

// Low queue jobs (QueueName::Low)
public function retryUntil(): DateTime
{
    return now()->addHours(24);
}
```

**Batch order** (by domain, smallest first):
1. **Reviews.io** (2 jobs): `SyncProductRatingsJob`, `UpdateShopwiredRatingsJob`
2. **Inventory** (2 jobs): `SyncDeltaStockToShopwiredJob`, `SyncFullStockToShopwiredJob`
3. **Linnworks** (6 jobs): `SyncLinnworksOrdersByCursorJob`, `SyncLinnworksOrdersJob`, `SyncLinnworksStockItemsJob`, `SyncLinnworksSuppliersJob`, `SyncStockItemJob`, `SyncStockItemsWithCursorJob`
4. **Mixpanel** (5 jobs): `SyncBingAdsToMixpanelJob`, `SyncCampaignLookupTableJob`, `SyncGoogleAdsToMixpanelJob`, `SyncOrderLookupTableJob`, `SyncProductLookupTableJob`
5. **Shopwired bulk** (9 jobs): `SyncShopwiredBrandsJob`, `SyncShopwiredCategoriesJob`, `SyncShopwiredCustomFieldsJob`, `SyncShopwiredCustomersJob`, `SyncShopwiredFilterGroupsJob`, `SyncShopwiredOrdersJob`, `SyncShopwiredProductsJob`, `SyncShopwiredOrdersRangeJob`, `ReconcileShopwiredProductsJob`
6. **Mixpanel special**: `SyncOrdersToMixpanelJob` (remove now-redundant MissingRequiredDataException catch)
7. **Remaining** (4 jobs): `CleanupWebhookEventsJob`, `ProcessShopwiredWebhookHealthJob`, `ProcessProductSearchFeedJob`, `CleanupStaleContactActionsJob` (keeps inner foreach error handling)

**NOT refactored** (Pattern B — specialized catch blocks, keep explicit catches):
- `UpdateSkuJob` — 6 specific exception catches with different behaviors per type
- `ProcessContactSubmissionJob` — repository action tracking per exception type
- `SetProductFreeDeliveryJob` — batch result handling, custom re-dispatch of failed items

**Partially refactored** (middleware for outer catch, keep inner logic):
- `CleanupStaleContactActionsJob` — inner per-action foreach error handling stays, outer try/catch replaced by middleware
- `SyncOrdersToMixpanelJob` — MissingRequiredDataException catch is redundant (middleware Throwable handles it identically via fail()+return), can be removed

### Step 5: Add `ThrottlesExceptions` per-service

Add to `middleware()` array on applicable jobs:

```php
public function middleware(): array
{
    return [
        (new ThrottlesExceptions(maxAttempts: 10, decaySeconds: 300))
            ->by('linnworks')
            ->when(static fn(Throwable $e): bool => $e instanceof TransientApiFailure),
        new HandleApiExceptions(),
    ];
}
```

**Service keys and affected jobs**:
- `'linnworks'` — 6 Linnworks jobs + `SyncStockItemJob`, `SyncStockItemsWithCursorJob`
- `'shopwired'` — 14+ Shopwired jobs (entity syncs, bulk syncs, webhook health)
- `'mixpanel'` — 6 Mixpanel jobs
- `'reviewsio'` — 2 Reviews.io jobs
- `'helpscout'` — `ProcessContactSubmissionJob`

**Inventory stock sync jobs** (`SyncDeltaStockToShopwiredJob`, `SyncFullStockToShopwiredJob`) read from Linnworks AND write to ShopWired — use **both** service keys:
```php
public function middleware(): array
{
    return [
        (new ThrottlesExceptions(10, 300))->by('linnworks')->when(fn($e) => $e instanceof TransientApiFailure),
        (new ThrottlesExceptions(10, 300))->by('shopwired')->when(fn($e) => $e instanceof TransientApiFailure),
        new HandleApiExceptions(),
    ];
}
```

**Confirmed parameters**:
- `maxAttempts: 10` — 10 failures before circuit opens
- `decaySeconds: 300` — circuit stays open for 5 minutes
- `->when(TransientApiFailure)` — only circuit-break on service unavailability, not data errors
- Uniform across all services

**Pattern B jobs also get ThrottlesExceptions** (but NOT HandleApiExceptions):
- `UpdateSkuJob` → `->by('linnworks')`
- `ProcessContactSubmissionJob` → `->by('helpscout')` + add `$maxExceptions`
- `SetProductFreeDeliveryJob` → `->by('shopwired')` + add `$maxExceptions`

**Middleware ordering** (critical):
```php
return [
    (new ThrottlesExceptions(10, 300))->by('service')->when(...), // Outer: circuit breaker
    new HandleApiExceptions(),                                     // Inner: error handling
];
```
ThrottlesExceptions must be first (outermost) so it can:
1. Short-circuit when circuit is OPEN (release without calling handle())
2. Count exceptions that propagate through HandleApiExceptions (only TransientApiFailure without retryAfter)

**Circuit breaker counting behavior**: When HandleApiExceptions calls `$job->release()` for TransientApiFailure with retryAfter, it returns without throwing — ThrottlesExceptions never sees it. The circuit only opens from rethrown TransientApiFailures (retryAfter=null). This is correct: when the API provides retry-after headers, it's managing its own backpressure. The circuit breaker only activates for unmanaged failures.

### Step 6: Update tests for each batch

For each refactored job test file:

**Remove**: Exception handling test sections (Transient/Permanent/Unexpected — now tested in middleware test)

**Keep**:
- Success path tests (handle() happy path with use case mock + logger assertions)
- failed() callback tests (unchanged)
- Job property tests (uniqueId, etc.)

**Add**:
- `it_returns_correct_middleware()` test verifying `middleware()` returns expected array

**Update**: Tests that verify `fail()` is called + exception is rethrown → update to verify `fail()` is called + no exception propagates (for the permanent failure path, which is now in middleware only)

**New middleware test** covers all exception scenarios once, instead of duplicating across ~16 test files.

---

## Files Modified Summary

| Category | Files | Action |
|----------|-------|--------|
| New middleware | 1 | `app/Infrastructure/Jobs/Middleware/HandleApiExceptions.php` |
| New middleware test | 1 | `tests/Unit/Infrastructure/Jobs/Middleware/HandleApiExceptionsTest.php` |
| Service provider | 1 | `app/Providers/QueueObservabilityServiceProvider.php` (add Queue::failing → Sentry + Queue::after completion log) |
| Abstract job | 1 | `AbstractSyncShopwiredEntityJob.php` (remove withErrorHandling, add middleware) |
| Pattern A jobs | ~25 | Remove try/catch, add middleware(), adjust $tries/$maxExceptions, add $failOnTimeout + retryUntil() |
| Pattern B jobs | 3 | Add ThrottlesExceptions middleware + $maxExceptions (keep existing catch blocks) |
| Existing job tests | ~9 | Simplify (remove exception tests, add middleware assertion) |
| Jobs CLAUDE.md | 1 | Update to document middleware pattern + Skip middleware guidance for future jobs |

**Minimally touched** (Pattern B — only ThrottlesExceptions added):
- `UpdateSkuJob` — add middleware() with ThrottlesExceptions('linnworks')
- `ProcessContactSubmissionJob` — add middleware() with ThrottlesExceptions('helpscout') + $maxExceptions
- `SetProductFreeDeliveryJob` — add middleware() with ThrottlesExceptions('shopwired') + $maxExceptions

---

## Verification

1. `make test` — all existing tests pass after each batch
2. `make lint` — PHPStan, Pint, Arkitect, Deptrac pass
3. Manual verification: dispatch a job, verify middleware catches exceptions correctly
4. Sentry: trigger a job failure (e.g., dispatch with bad data), confirm it appears in Sentry dashboard via Queue::failing
5. ThrottlesExceptions: verify circuit breaker keys appear in Redis cache after failures
