<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Mixpanel;

use App\Infrastructure\Jobs\Enums\QueueName;
use App\Application\Mixpanel\UseCases\SyncOrdersToMixpanelUseCase;
use App\Domain\Exceptions\Api\AbstractApiException;
use App\Domain\Exceptions\Api\PermanentApiFailure;
use App\Domain\Exceptions\Api\TransientApiFailure;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

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
     * 3 attempts: quick retries for transient issues + 1hr fallback for longer outages.
     */
    public int $tries = 3;

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
     * Execute the job.
     *
     * @throws TransientApiFailure When Mixpanel API unavailable (triggers retry)
     * @throws PermanentApiFailure When permanent API failure occurs (fails immediately)
     * @throws MissingRequiredDataException When customer data missing (permanent failure)
     * @throws Throwable When unexpected errors occur - indicates code update required
     */
    public function handle(SyncOrdersToMixpanelUseCase $useCase, LoggerInterface $logger): void
    {
        $fromString = $this->from->format('Y-m-d H:i:s');
        $toString = $this->to->format('Y-m-d H:i:s');

        $logger->info('Mixpanel order sync job starting', [
            'from' => $fromString,
            'to' => $toString,
        ]);

        try {
            $result = $useCase->execute($this->from, $this->to);

            $logger->info('Mixpanel order sync job completed', [
                'from' => $fromString,
                'to' => $toString,
                'orders_in_range' => $result->ordersInRange,
                'skipped' => $result->skipped,
                'synced' => $result->synced,
                'checkout_events' => $result->checkoutEventsCreated,
                'product_events' => $result->productEventsCreated,
            ]);
        } catch (MissingRequiredDataException $e) {
            $this->fail($e);
            throw $e;
        } catch (TransientApiFailure $e) {
            // Dual retry: API-provided delay via release(), or Laravel backoff via rethrow
            $logger->warning('Mixpanel order sync service unavailable, will retry', [
                'from' => $fromString,
                'to' => $toString,
                'service' => $e->serviceName,
                'retry_after' => $e->retryAfter,
                'attempts' => $this->attempts(),
            ]);

            if ($e->retryAfter !== null) {
                $this->release($e->retryAfter);
            } else {
                throw $e;
            }
        } catch (PermanentApiFailure $e) {
            $this->fail($e);
            throw $e;
        } catch (Throwable $e) {
            $this->fail($e);
            throw $e;
        }
    }

    /**
     * Handle job failure after all retries exhausted.
     */
    public function failed(Throwable $exception): void
    {
        $context = [
            'from' => $this->from->format('Y-m-d H:i:s'),
            'to' => $this->to->format('Y-m-d H:i:s'),
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ];

        if ($exception instanceof MissingRequiredDataException) {
            $context['data_type'] = $exception->dataType;
            $context['operation'] = $exception->operation;
            $context['resolution'] = $exception->resolution;
        }

        if ($exception instanceof AbstractApiException) {
            Log::error('Mixpanel order sync job failed permanently', $context);
        } else {
            Log::critical('Mixpanel order sync job failed permanently', $context);
        }
    }
}
