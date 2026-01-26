<?php

declare(strict_types=1);

namespace App\Presentation\Jobs\Shopwired;

use App\Application\Shopwired\UseCases\SyncOrdersRangeUseCase;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asynchronously synchronize ShopWired orders to local database (date-range based).
 *
 * Queues order synchronization from ShopWired API to PostgreSQL.
 * Implements exponential backoff retry strategy for API rate limits.
 *
 * Typical usage: hourly schedule with 2-hour overlap window.
 * For historical backfills: dispatch multiple jobs with smaller date ranges.
 *
 * @see SyncShopwiredOrdersJob For generator-based full/quick/micro sync
 */
final class SyncShopwiredOrdersRangeJob implements ShouldQueue
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

    public function __construct(
        private readonly DateTimeImmutable $from,
        private readonly DateTimeImmutable $to,
    ) {
        $this->onQueue('low');
    }

    /**
     * Execute the job.
     *
     * @throws ExternalServiceUnavailableException When ShopWired API unavailable - will retry
     * @throws InvalidApiResponseException When API contract violation (permanent failure)
     * @throws AuthenticationExpiredException When credentials invalid (permanent failure)
     * @throws Throwable When unexpected errors occur - indicates code update required
     */
    public function handle(SyncOrdersRangeUseCase $useCase): void
    {
        $fromString = $this->from->format('Y-m-d H:i:s');
        $toString = $this->to->format('Y-m-d H:i:s');

        Log::info('ShopWired order sync job starting', [
            'from' => $fromString,
            'to' => $toString,
        ]);

        try {
            $result = $useCase->execute($this->from, $this->to);

            Log::info('ShopWired order sync job completed', [
                'from' => $fromString,
                'to' => $toString,
                'fetched' => $result->fetched,
                'saved' => $result->saved,
                'failed' => $result->failed,
            ]);
        } catch (InvalidApiResponseException $e) {
            // Permanent failure - API contract changed, code needs updating
            Log::critical('API response validation failed during ShopWired sync', [
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
            Log::critical('Authentication failed during ShopWired sync', [
                'from' => $fromString,
                'to' => $toString,
                'service' => $e->serviceName,
                'error' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            $this->fail($e);
            throw $e;
        } catch (ExternalServiceUnavailableException $e) {
            Log::warning('ShopWired API unavailable, will retry', [
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
            Log::critical('Unexpected exception in ShopWired sync - code update required', [
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
        Log::error('ShopWired order sync job failed permanently', [
            'from' => $this->from->format('Y-m-d H:i:s'),
            'to' => $this->to->format('Y-m-d H:i:s'),
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }

    /**
     * Create a job for hourly scheduled sync with 2-hour overlap.
     *
     * The overlap ensures orders modified near sync boundaries are captured.
     */
    public static function hourly(): self
    {
        $to = new DateTimeImmutable('now');
        $from = $to->modify('-2 hours');

        return new self($from, $to);
    }
}
