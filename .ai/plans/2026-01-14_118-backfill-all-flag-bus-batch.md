# Plan: Add --all Flag to BackfillShopwiredOrdersCommand with Bus::batch()

## Summary

Extend `BackfillShopwiredOrdersCommand` to support syncing ALL historical orders from a configured start date, using Laravel's `Bus::batch()` for tracking and Slack notifications on completion/failure.

## Prerequisites

### 1. Set Up Queue Priority Tiers

Implement a 3-tier queue system to prevent bulk backfill jobs from blocking time-sensitive operations.

| Queue | Purpose | Examples |
|-------|---------|----------|
| `high` | Webhooks, user-triggered, time-sensitive | Webhook handlers, cache invalidation |
| `default` | Regular scheduled operations | Hourly order sync, daily ad spend sync |
| `low` | Bulk operations, backfills, reports | `--all` backfill (~70 jobs), data exports |

**Files to modify:**
- `config/horizon.php` — Update supervisor queue order to `['high', 'default', 'low']`
- `config/horizon.php` — Add `waits` thresholds for new queues (for LongWaitDetected events)
- Existing jobs — Audit and assign appropriate queues (most stay on `default`)

---

### 2. Set Up Slack Logging

Configure Slack webhook for job completion/failure notifications. This enables the Bus::batch() callbacks to alert on backfill status.

---

### 3. Add Batchable Trait to SyncShopwiredOrdersJob

**File:** `app/Presentation/Jobs/SyncShopwiredOrdersJob.php`

Bus::batch() requires jobs to use the `Batchable` trait for proper batch integration.

```php
use Illuminate\Bus\Batchable;

final class SyncShopwiredOrdersJob implements ShouldQueue
{
    use Batchable;  // <-- Add this
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    // ...
}
```

**Why:** Without this trait, jobs won't have access to `$this->batch()` method, and batch tracking will be incomplete.

---

## Changes

### 1. Config: Add earliest_order_date
**File:** `config/shopwired.php`

```php
'earliest_order_date' => env('SHOPWIRED_EARLIEST_ORDER_DATE', '2018-01-01'),
```

User will set the actual date in `.env` after querying ShopWired manually.

---

### 2. Command: Extend BackfillShopwiredOrdersCommand
**File:** `app/Presentation/Console/Commands/BackfillShopwiredOrdersCommand.php`

**New options:**
- `--all` — Sync from `earliest_order_date` to today (ignores `--months` and `--offset`)
- `--batch` — Use Bus::batch() for tracking (implied when `--all` is used)

**Logic changes:**
1. When `--all` is passed:
   - Read `config('shopwired.earliest_order_date')`
   - Calculate months from start date to today
   - Force `--batch` behavior

2. When `--batch` is used:
   - Wrap jobs in `Bus::batch()`
   - Add `then()` callback → Log completion to Slack
   - Add `catch()` callback → Log failure to Slack
   - **Must use `->onQueue('low')`** to dispatch to low priority queue
   - Display batch ID for monitoring

**Updated signature:**
```php
protected $signature = 'shopwired:backfill-orders
    {--months=12 : Number of months to sync}
    {--offset=0 : Start from X months ago}
    {--all : Sync ALL orders from earliest_order_date}
    {--batch : Use Bus::batch() for tracking}
    {--dry-run : Show what would be dispatched}';
```

---

### 3. Bus::batch() Implementation

**Important:** Queue must be set on the batch, not individual jobs.

```php
Bus::batch($jobs)
    ->name('ShopWired Order Backfill')
    ->onQueue('low')  // Required - dispatches all jobs to low queue
    ->allowFailures() // Let all 70 jobs run even if some fail
    ->then(function (Batch $batch) {
        // Log to Slack if configured, otherwise standard logging
        $channel = config('logging.channels.slack.url') ? 'slack' : 'stack';
        Log::channel($channel)->info('Order backfill complete', [
            'batch_id' => $batch->id,
            'total_jobs' => $batch->totalJobs,
            'processed' => $batch->processedJobs(),
            'failed' => $batch->failedJobs,
        ]);
    })
    ->catch(function (Batch $batch, Throwable $e) {
        $channel = config('logging.channels.slack.url') ? 'slack' : 'stack';
        Log::channel($channel)->error('Order backfill had failures', [
            'batch_id' => $batch->id,
            'failed_jobs' => $batch->failedJobs,
            'exception' => $e->getMessage(),
            'retry_cmd' => "php artisan queue:retry-batch {$batch->id}",
        ]);
    })
    ->dispatch();
```

**Behavior notes:**
- `->allowFailures()` ensures all 70 monthly jobs run even if one fails permanently
- `catch()` fires on first permanent failure but does NOT stop other queued jobs
- Jobs already dispatched to queue continue processing regardless of failures

---

## Implementation Steps

1. **Set up queue tiers** — Configure Horizon with `high`, `default`, `low` queues + wait thresholds
2. **Set up Slack logging** — Configure webhook for notifications
3. **Add Batchable trait** to `SyncShopwiredOrdersJob`
4. **Add config value** to `config/shopwired.php`
5. **Update command signature** with new options
6. **Add month calculation logic** for `--all` flag
7. **Implement Bus::batch() dispatch** with `->onQueue('low')` and callbacks
8. **Add Slack fallback** in batch callbacks (check if channel configured)
9. **Update command output** to show batch ID when applicable
10. **Test with --dry-run** to verify month calculations

---

## Files to Modify

| File | Change |
|------|--------|
| `config/horizon.php` | Add queue tier configuration + wait thresholds |
| `config/logging.php` | Slack channel setup (if not already configured) |
| `config/shopwired.php` | Add `earliest_order_date` |
| `app/Presentation/Jobs/SyncShopwiredOrdersJob.php` | Add `Batchable` trait |
| `app/Presentation/Console/Commands/BackfillShopwiredOrdersCommand.php` | Add `--all`, `--batch`, Bus::batch() |

---

## Usage Examples

```bash
# Sync ALL orders (uses Bus::batch automatically, dispatches to 'low' queue)
php artisan shopwired:backfill-orders --all

# Dry run to see what would be dispatched
php artisan shopwired:backfill-orders --all --dry-run

# Traditional usage still works (dispatches to 'default' queue)
php artisan shopwired:backfill-orders --months=12

# Traditional with batch tracking
php artisan shopwired:backfill-orders --months=12 --batch
```

---

## Verification

1. **Dry run test:** `php artisan shopwired:backfill-orders --all --dry-run`
   - Verify correct date range (earliest to today)
   - Verify month count matches expectations (~70 months for 6 years)

2. **Queue verification:**
   - Run `--all` and check Horizon shows jobs on `low` queue
   - Verify `high` and `default` queues are not blocked

3. **Small batch test:** `php artisan shopwired:backfill-orders --months=2 --batch`
   - Verify batch ID is displayed
   - Verify jobs appear in Horizon
   - Check `job_batches` table has entry

4. **Completion callback:**
   - After batch completes, verify Slack message received (if configured)
   - Or check logs for completion entry

5. **Failure test (optional):**
   - Mock a job failure
   - Verify `catch()` callback fires after retries exhausted
   - Verify other jobs continue (due to `allowFailures()`)
   - Test `queue:retry-batch {id}` command

---

## Review Notes

Issues identified during critical review (2024-01-14):

| Severity | Issue | Resolution |
|----------|-------|------------|
| HIGH | `SyncShopwiredOrdersJob` missing `Batchable` trait | Added as prerequisite step 3 |
| MEDIUM | `->onQueue('low')` not explicit | Added to implementation example |
| MEDIUM | Slack channel fallback needed | Added conditional check in callbacks |
| LOW | Horizon `waits` for new queues | Added to prerequisite step 1 |
| LOW | `allowFailures()` behavior | Added to implementation + documented behavior |
