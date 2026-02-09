# Plan: Cross-Cutting Job Refactor (21 Jobs)

Single PR applying 8 improvements + 3 additional checks across all jobs.

## Decisions Made

| Decision | Choice |
|----------|--------|
| After `$this->fail($e)` | Keep `throw $e` (preserves `@throws`, conventional) |
| Logger in `handle()` | `LoggerInterface` via DI |
| Logger in `failed()` | `Log` facade (no DI available — framework lifecycle hook) |
| PR strategy | Single PR |

---

## Phase 0: Fix PHPStan Rule Gap (do first to verify it catches the bug)

**File:** `app/DevTools/PHPStan/Rules/Jobs/JobMustCallOnQueueRule.php`

**Bug:** Rule implements `Rule<ClassMethod>` and only inspects `__construct` nodes. If a job class has no constructor, the rule never fires — the job silently defaults to the `'default'` queue. This is how `SyncCampaignLookupTableJob` slipped through.

**Fix:** Refactor to `Rule<InClassNode>` (matching sibling rules `JobRequiredMethodsRule` and `JobRequiredPropertiesRule`):

```php
/**
 * @implements Rule<InClassNode>
 */
final class JobMustCallOnQueueRule implements Rule
{
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $node->getClassReflection();

        if (! $this->isJobClass($classReflection->getName())) {
            return [];
        }

        if ($classReflection->isAbstract()) {
            return [];
        }

        // Find __construct in the AST
        $classNode = $node->getOriginalNode();
        $constructor = null;
        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof ClassMethod && $stmt->name->toString() === '__construct') {
                $constructor = $stmt;
                break;
            }
        }

        if ($constructor === null) {
            return [
                RuleErrorBuilder::message(
                    'Job must have a constructor that calls $this->onQueue() to explicitly assign a queue tier.',
                )->identifier('alz.jobMustCallOnQueue')->build(),
            ];
        }

        // Check constructor contains onQueue() call (existing logic)
        foreach ($constructor->stmts ?? [] as $stmt) {
            if ($this->containsOnQueueCall($stmt)) {
                return [];
            }
        }

        return [
            RuleErrorBuilder::message(
                'Job must call $this->onQueue() in the constructor to explicitly assign a queue tier.',
            )->identifier('alz.jobMustCallOnQueue')->build(),
        ];
    }
}
```

**Imports change:** Replace `ClassMethod` node import with `InClassNode` + keep `ClassMethod` for AST traversal.

**Verification:** Run `make lint` — should now report error on `SyncCampaignLookupTableJob` (no constructor). Then fix that job in Phase 1C.

---

## Phase 1: Zero-Risk Cleanup (no behavioral change, no test impact)

### 1A. Remove `SerializesModels` trait — ALL 21 jobs

No job has Eloquent model properties. Constructor params are primitives, DTOs, DateTimeImmutable, or value objects.

- Remove `use SerializesModels;` from trait list
- Remove `use Illuminate\Queue\SerializesModels;` import

### 1B. Remove redundant comments — ALL jobs with Throwable catch

Remove `// Unexpected exception = code needs updating` comments (the log message already conveys this).

### 1C. Fix `SyncCampaignLookupTableJob` missing constructor

**Bug:** No constructor, no `onQueue()` call — silently defaults to `'default'`. Now caught by the fixed PHPStan rule from Phase 0. Add constructor:

```php
public function __construct()
{
    $this->onQueue(QueueName::Default->value);
}
```

(Will use the QueueName enum from Phase 2. Use `'default'` string temporarily if Phase 2 not yet applied.)

---

## Phase 2: QueueName Enum

### 2A. Create enum

**New file:** `app/Application/Jobs/Enums/QueueName.php`

```php
enum QueueName: string
{
    case High = 'high';
    case Default = 'default';
    case Low = 'low';
}
```

Lives in ApplicationJobs sub-layer (queue infrastructure concern, not domain).

### 2B. Replace hardcoded strings — ALL 21 jobs

`$this->onQueue('low')` → `$this->onQueue(QueueName::Low->value)`

---

## Phase 3: Consolidate Logging (behavioral change — test impact)

**Core problem:** Catch blocks log + call `$this->fail($e)` which internally calls `failed()` → duplicate logging.

**Fix:** Remove logging from permanent/throwable catch blocks. Make `failed()` severity-aware. Keep transient logging (it's the only log for retry attempts).

### 3A. Severity-aware `failed()` — ALL 21 jobs

Replace uniform `Log::error(...)` with severity logic:

```php
public function failed(Throwable $exception): void
{
    $level = match (true) {
        $exception instanceof PermanentApiFailure => 'error',
        default => 'critical',
    };

    Log::{$level}('[JobName] failed permanently', [
        'exception' => $exception::class,
        'message' => $exception->getMessage(),
        'attempts' => $this->attempts(),
        // ... job-specific context preserved
    ]);
}
```

- `PermanentApiFailure` → `error` (known failure mode, expected)
- Everything else (unexpected Throwable, MissingRequiredDataException, etc.) → `critical` (code needs attention)
- `TransientApiFailure` reaching `failed()` = all retries exhausted → `error`

Refined severity (PermanentApiFailure & TransientApiFailure = `error`, everything else = `critical`):

```php
$level = $exception instanceof AbstractApiException ? 'error' : 'critical';
```

### 3B. Remove catch block logging — Pattern A (16 jobs)

PermanentApiFailure and Throwable catches become:

```php
catch (PermanentApiFailure $e) {
    $this->fail($e);
    throw $e;
} catch (Throwable $e) {
    $this->fail($e);
    throw $e;
}
```

Transient catch **keeps** its `Log::warning(...)` (only log for retries).

### 3C. Pattern B jobs (UpdateSkuJob, ProcessContactSubmissionJob)

**These have intentional different catch blocks with side effects beyond logging.** Approach:

- **UpdateSkuJob:** SkuUpdateFailedException catch has unique context (`old_sku`, `new_sku`, `failed_system`). Move this context to `failed()` with instanceof check. Remove Log calls from catch blocks. Keep `$this->fail($e); throw $e;`.
- **ProcessContactSubmissionJob:** Check if catch blocks have side effects beyond logging. If only logging + fail + throw, same treatment as Pattern A. If repository calls or other side effects exist, keep catch structure minus logging.

### 3D. Pattern C / Special jobs

- **SyncOrdersToMixpanelJob:** MissingRequiredDataException catch has specific context (`data_type`, `operation`, `resolution`). Move to `failed()` with instanceof check. Remove catch block logging.
- **CleanupStaleContactActionsJob:** Throwable catch — remove logging, keep fail + throw. Transient catch — keeps its logging (no release, just rethrow).
- **SetProductFreeDeliveryJob:** Throwable catch — remove logging, keep fail + throw.
- **ProcessProductSearchFeedJob:** StorageOperationFailedException catch just rethrows (no fail) — keep its warning log (it's a retry signal). Throwable catch — remove logging.

### 3E. Add dual retry documentation — ALL jobs with TransientApiFailure catch

```php
catch (TransientApiFailure $e) {
    // Dual retry: API-provided delay via release(), or Laravel backoff via rethrow
    ...
}
```

Applies to 18 jobs (all except SetProductFreeDeliveryJob, CleanupStaleContactActionsJob's transient just rethrows without release).

---

## Phase 4: ShouldBeUnique

### Already unique (4 jobs)
- UpdateSkuJob, ProcessContactSubmissionJob, SyncShopwiredCustomersJob, SyncShopwiredOrdersJob

### Add ShouldBeUnique (12 parameterless jobs)

| Job | uniqueFor | Rationale |
|-----|-----------|-----------|
| SyncShopwiredCustomFieldsJob | 120 | timeout=60 + buffer |
| SyncShopwiredFilterGroupsJob | 120 | timeout=60 + buffer |
| SyncShopwiredProductsJob | 1200 | timeout=900 + buffer |
| ReconcileShopwiredProductsJob | 600 | timeout=300 + buffer |
| SyncLinnworksStockItemsJob | 4200 | timeout=3600 + buffer |
| SyncCampaignLookupTableJob | 600 | timeout=300 + buffer |
| SyncOrderLookupTableJob | 600 | timeout=300 + buffer |
| SyncProductLookupTableJob | 600 | timeout=300 + buffer |
| SyncProductRatingsJob | 1200 | timeout=900 + buffer |
| UpdateShopwiredRatingsJob | 1200 | timeout=900 + buffer |
| ProcessProductSearchFeedJob | 900 | timeout=600 + buffer |
| CleanupStaleContactActionsJob | 300 | timeout=120 + buffer |

Each gets a `uniqueId()` returning a fixed kebab-case string (e.g., `'sync-shopwired-custom-fields'`).

### NOT adding ShouldBeUnique (5 parameterized jobs)

These are dispatched with different parameters per invocation — each represents distinct work:
- SyncShopwiredOrdersRangeJob (date range)
- SyncBingAdsToMixpanelJob (date range)
- SyncGoogleAdsToMixpanelJob (date range)
- SyncOrdersToMixpanelJob (date range)
- SetProductFreeDeliveryJob (command batch)

---

## Phase 5: LoggerInterface Injection

### In `handle()` — ALL 21 jobs

Add `LoggerInterface $logger` parameter. Replace `Log::info(...)` and `Log::warning(...)` with `$logger->info(...)` / `$logger->warning(...)`.

```php
public function handle(SyncCustomFieldsUseCase $useCase, LoggerInterface $logger): void
{
    $logger->info('ShopWired custom field definitions sync job starting');
    // ...
    $logger->warning('ShopWired custom field sync service unavailable, will retry', [...]);
}
```

Import: `use Psr\Log\LoggerInterface;`

### In `failed()` — keep Log facade

```php
public function failed(Throwable $exception): void
{
    Log::{$level}('...', [...]);  // Facade — no DI in lifecycle methods
}
```

---

## Phase 6: $tries vs $maxExceptions Analysis

### The interaction

- `$tries` counts every worker pickup (including after `release()`)
- `$maxExceptions` counts only thrown exceptions
- Job fails when **either** limit is reached

### Problem scenario (current)

With `$tries = 3`, no `$maxExceptions`:
1. Attempt 1: API gives retry-after → `release(30)` → tries=1
2. Attempt 2: API gives retry-after → `release(30)` → tries=2
3. Attempt 3: Still down, no retry-after → throw → tries=3, job fails

Only 1 actual exception thrown, but 3 tries exhausted by releases.

### Recommendation: Add `$maxExceptions` to critical eventual-consistency jobs

Jobs where data consistency matters and multiple retries should be tolerated:

| Job | Current $tries | Proposed $maxExceptions | Effect |
|-----|---------------|------------------------|--------|
| SyncShopwiredOrdersJob | 3 | 3 | Releases don't eat into exception budget |
| SyncShopwiredCustomersJob | 3 | 3 | Same |
| SyncLinnworksStockItemsJob | 2 | 2 | Same |
| UpdateSkuJob | 3 | 3 | Decouples release cycles from exception limit |
| ProcessContactSubmissionJob | 5 | 5 | Same |

**Not adding to other jobs:** Weekly/daily batch jobs where the scenario is unlikely, or jobs without release() paths.

---

## Phase 7: Trait/Interface Audit

After removing `SerializesModels`, remaining traits:
- `Dispatchable` — needed for `::dispatch()` static method ✅
- `InteractsWithQueue` — needed for `fail()`, `release()`, `attempts()` ✅
- `Queueable` — needed for `onQueue()` ✅

All interfaces verified:
- `ShouldQueue` — required for all queued jobs ✅
- `ShouldBeUnique` — will be added where appropriate (Phase 4) ✅

No unused interfaces or traits found.

---

## Test Updates Required

### 5 test files affected by Phase 3 (logging consolidation) + Phase 5 (LoggerInterface):

| Test File | Type | Changes Needed |
|-----------|------|----------------|
| `tests/Unit/.../SyncOrdersToMixpanelJobTest.php` | Unit | Remove catch-block log assertions, update failed() assertions for severity-aware logging, inject LoggerInterface mock |
| `tests/Unit/.../SyncBingAdsToMixpanelJobTest.php` | Unit | Same — strictest file, 10+ exact message matches to update |
| `tests/Unit/.../ProcessProductSearchFeedJobTest.php` | Unit | Same pattern |
| `tests/Feature/.../SyncGoogleAdsToMixpanelJobTest.php` | Feature | Uses Mockery::on() — more flexible, update callback assertions |
| `tests/Feature/.../SyncCampaignLookupTableJobTest.php` | Feature | Same — add constructor test for Phase 1C fix |

**Strategy:** For each test:
1. Remove assertions for `Log::critical`/`Log::error` in catch-block paths
2. Update `failed()` callback tests to assert severity-aware level
3. Replace `Log::shouldReceive('info')`/`Log::shouldReceive('warning')` in handle() paths with LoggerInterface mock expectations
4. Keep `Log::shouldReceive` for `failed()` method tests (facade stays)

---

## Verification

1. `make fix` — auto-fix code style (Pint ordered_imports after import changes)
2. `make lint` — PHPStan + PHPArkitect + Deptrac (verify new enum placement, imports)
3. `make test` — all tests pass with updated assertions
4. Manual check: Verify no duplicate log entries by tracing `fail()` → `failed()` path
5. Verify Deptrac allows `ApplicationJobs` → `Application\Jobs\Enums\QueueName`

---

## File List

### New files (1)
- `app/Application/Jobs/Enums/QueueName.php`

### Modified files (22 jobs/rules + 5 tests = 27)
**PHPStan rule:**
- `app/DevTools/PHPStan/Rules/Jobs/JobMustCallOnQueueRule.php`

**Jobs:**
- `app/Application/Jobs/ContactForm/CleanupStaleContactActionsJob.php`
- `app/Application/Jobs/ContactForm/ProcessContactSubmissionJob.php`
- `app/Application/Jobs/Feeds/ProcessProductSearchFeedJob.php`
- `app/Application/Jobs/Inventory/UpdateSkuJob.php`
- `app/Application/Jobs/Linnworks/SyncLinnworksStockItemsJob.php`
- `app/Application/Jobs/Mixpanel/SyncBingAdsToMixpanelJob.php`
- `app/Application/Jobs/Mixpanel/SyncCampaignLookupTableJob.php`
- `app/Application/Jobs/Mixpanel/SyncGoogleAdsToMixpanelJob.php`
- `app/Application/Jobs/Mixpanel/SyncOrderLookupTableJob.php`
- `app/Application/Jobs/Mixpanel/SyncOrdersToMixpanelJob.php`
- `app/Application/Jobs/Mixpanel/SyncProductLookupTableJob.php`
- `app/Application/Jobs/ReviewsIo/SyncProductRatingsJob.php`
- `app/Application/Jobs/ReviewsIo/UpdateShopwiredRatingsJob.php`
- `app/Application/Jobs/Shopwired/ReconcileShopwiredProductsJob.php`
- `app/Application/Jobs/Shopwired/SetProductFreeDeliveryJob.php`
- `app/Application/Jobs/Shopwired/SyncShopwiredCustomFieldsJob.php`
- `app/Application/Jobs/Shopwired/SyncShopwiredCustomersJob.php`
- `app/Application/Jobs/Shopwired/SyncShopwiredFilterGroupsJob.php`
- `app/Application/Jobs/Shopwired/SyncShopwiredOrdersJob.php`
- `app/Application/Jobs/Shopwired/SyncShopwiredOrdersRangeJob.php`
- `app/Application/Jobs/Shopwired/SyncShopwiredProductsJob.php`

**Tests:**
- `tests/Unit/Application/Jobs/Mixpanel/SyncOrdersToMixpanelJobTest.php`
- `tests/Unit/Application/Jobs/Mixpanel/SyncBingAdsToMixpanelJobTest.php`
- `tests/Unit/Application/Jobs/Feeds/ProcessProductSearchFeedJobTest.php`
- `tests/Feature/Application/Jobs/Mixpanel/SyncGoogleAdsToMixpanelJobTest.php`
- `tests/Feature/Application/Jobs/Mixpanel/SyncCampaignLookupTableJobTest.php`

### Execution order
Phase 0 (PHPStan rule fix) → verify with `make lint` → Phase 1-7 (job changes)
