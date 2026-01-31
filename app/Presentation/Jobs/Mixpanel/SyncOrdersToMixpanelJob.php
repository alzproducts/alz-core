<?php

declare(strict_types=1);

namespace App\Presentation\Jobs\Mixpanel;

use App\Application\Mixpanel\UseCases\SyncOrdersToMixpanelUseCase;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
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
    use SerializesModels;

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
        $this->onQueue('low');
    }

    /**
     * Execute the job.
     *
     * @throws ExternalServiceUnavailableException When Mixpanel API unavailable - will retry
     * @throws UnexpectedApiResultException When export data invalid (permanent failure)
     * @throws AuthenticationExpiredException When credentials invalid (permanent failure)
     * @throws MissingRequiredDataException When customer data missing (permanent failure)
     * @throws Throwable When unexpected errors occur - indicates code update required
     */
    public function handle(SyncOrdersToMixpanelUseCase $useCase): void
    {
        $fromString = $this->from->format('Y-m-d H:i:s');
        $toString = $this->to->format('Y-m-d H:i:s');

        Log::info('Mixpanel order sync job starting', [
            'from' => $fromString,
            'to' => $toString,
        ]);

        try {
            $result = $useCase->execute($this->from, $this->to);

            Log::info('Mixpanel order sync job completed', [
                'from' => $fromString,
                'to' => $toString,
                'orders_in_range' => $result->ordersInRange,
                'skipped' => $result->skipped,
                'synced' => $result->synced,
                'checkout_events' => $result->checkoutEventsCreated,
                'product_events' => $result->productEventsCreated,
            ]);
        } catch (UnexpectedApiResultException $e) {
            // Permanent failure - Mixpanel export data is invalid or missing
            // Could indicate frontend tracking issues
            Log::critical('Mixpanel export data invalid during order sync', [
                'from' => $fromString,
                'to' => $toString,
                'service' => $e->serviceName,
                'error' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            $this->fail($e);
            throw $e;
        } catch (AuthenticationExpiredException $e) {
            // Permanent failure - credentials need fixing, don't waste retries
            Log::critical('Authentication failed during Mixpanel order sync', [
                'from' => $fromString,
                'to' => $toString,
                'service' => $e->serviceName,
                'error' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            $this->fail($e);
            throw $e;
        } catch (MissingRequiredDataException $e) {
            // Permanent failure - prerequisite data not available
            Log::critical('Missing required data during Mixpanel order sync', [
                'from' => $fromString,
                'to' => $toString,
                'data_type' => $e->dataType,
                'operation' => $e->operation,
                'resolution' => $e->resolution,
                'attempts' => $this->attempts(),
            ]);

            $this->fail($e);
            throw $e;
        } catch (ExternalServiceUnavailableException $e) {
            Log::warning('Mixpanel API unavailable, will retry', [
                'from' => $fromString,
                'to' => $toString,
                'service' => $e->serviceName,
                'retry_after' => $e->retryAfter ?? 'using backoff',
                'attempts' => $this->attempts(),
            ]);

            // Use API's retry delay if provided, otherwise let Laravel use backoff array
            if ($e->retryAfter !== null) {
                $this->release($e->retryAfter);
            } else {
                throw $e;
            }
        } catch (Throwable $e) {
            // Unexpected exception = code needs updating
            Log::critical('Unexpected exception in Mixpanel order sync - code update required', [
                'job' => self::class,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'from' => $fromString,
                'to' => $toString,
                'attempts' => $this->attempts(),
            ]);

            $this->fail($e);
            throw $e;
        }
    }

    /**
     * Handle job failure after all retries exhausted.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Mixpanel order sync job failed permanently', [
            'from' => $this->from->format('Y-m-d H:i:s'),
            'to' => $this->to->format('Y-m-d H:i:s'),
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
