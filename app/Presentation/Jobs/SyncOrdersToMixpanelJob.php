<?php

declare(strict_types=1);

namespace App\Presentation\Jobs;

use App\Application\Mixpanel\UseCases\SyncOrdersToMixpanelUseCase;
use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\MissingRequiredDataException;
use App\Domain\Exceptions\UnexpectedApiResultException;
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
 */
final class SyncOrdersToMixpanelJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Maximum number of attempts before giving up.
     */
    public int $tries = 5;

    /**
     * Seconds to wait before retrying (exponential backoff).
     *
     * @var array<int>
     */
    public array $backoff = [60, 120, 240, 480, 960];

    public function __construct(
        private readonly DateTimeImmutable $from,
        private readonly DateTimeImmutable $to,
    ) {}

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
