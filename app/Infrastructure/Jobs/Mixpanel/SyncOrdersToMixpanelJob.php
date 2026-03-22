<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Mixpanel;

use App\Application\Mixpanel\UseCases\SyncOrdersToMixpanelUseCase;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Asynchronously synchronize orders to Mixpanel analytics.
 *
 * Syncs "Checkout Completed" and "Product Purchased" events for orders
 * not already tracked by the frontend JavaScript SDK.
 *
 * Scheduled to run daily at 2:00 AM Europe/London with 24-hour lookback.
 * Uses pre-export deduplication to prevent duplicate events.
 *
 * ## Required Data
 *
 * 1. **shopwired.orders** — Orders in the date range (with products, discounts, refunds)
 * 2. **shopwired.customers** — Customer `is_trade` status for each order's customer
 * 3. **Mixpanel Export API** — Existing order hashes for deduplication
 *
 * ## Common Failure: MissingRequiredDataException
 *
 * If customers referenced by orders don't exist in `shopwired.customers`, the job fails.
 * This happens when new customers placed orders but haven't been synced yet.
 *
 * **Resolution:** Run a customer sync first, then retry this job.
 * - Quick sync (ALL trade + recent non-trade): `SyncShopwiredCustomersJob::dispatch(null, 5)`
 * - Full sync (all ~68k customers, ~45 min): `SyncShopwiredCustomersJob::dispatch()`
 *
 * Quick sync is usually sufficient since trade customers (~466) fit in ~5 pages,
 * so `maxTradePages=null` fetches 100% of trade accounts.
 */
final class SyncOrdersToMixpanelJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Maximum number of attempts before giving up.
     *
     * Doubled from original 3 to allow middleware-consumed attempts.
     */
    public int $tries = 6;

    /**
     * Maximum number of unhandled exceptions before failing.
     *
     * Matches original $tries — middleware-handled exceptions (release/fail)
     * don't count, only rethrown exceptions decrement this.
     */
    public int $maxExceptions = 3;

    /**
     * Fail the job if it times out.
     */
    public bool $failOnTimeout = true;

    /**
     * Seconds to wait before retrying.
     *
     * 1min, 5min, 1hr: quick retries catch transient issues, hour delay catches maintenance windows.
     *
     * @var array<int>
     */
    public array $backoff = [60, 300, 3600];

    /**
     * Job timeout in seconds.
     *
     * Mixpanel Export API can be slow for historical queries (generates on-demand).
     */
    public int $timeout = 600;

    public function __construct(
        private readonly DateTimeImmutable $from,
        private readonly DateTimeImmutable $to,
    ) {
        $this->onQueue(QueueName::Low->value);
    }

    /**
     * Job middleware pipeline.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            ServiceCircuitBreaker::mixpanel(),
            new HandleApiExceptions(),
        ];
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(24)->toDateTimeImmutable();
    }

    /**
     * Execute the job.
     */
    public function handle(SyncOrdersToMixpanelUseCase $useCase): void
    {
        $useCase->execute($this->from, $this->to);
    }
}
