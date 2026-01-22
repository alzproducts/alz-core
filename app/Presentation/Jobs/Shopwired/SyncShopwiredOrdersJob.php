<?php

declare(strict_types=1);

namespace App\Presentation\Jobs\Shopwired;

use App\Application\Shopwired\UseCases\SyncOrdersUseCase;
use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiResponseException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asynchronously synchronize ShopWired orders to local database.
 *
 * Supports full sync, quick sync, and micro sync modes with page limits.
 *
 * Usage:
 * - Full sync: SyncShopwiredOrdersJob::dispatch() — daily, all orders
 * - Quick sync: SyncShopwiredOrdersJob::dispatch(5) — hourly, ~500 orders
 * - Micro sync: SyncShopwiredOrdersJob::dispatch(1) — every 5 min, ~100 orders
 *
 * @see SyncShopwiredOrdersRangeJob For date-range based sync
 */
final class SyncShopwiredOrdersJob implements ShouldQueue
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
     * Create a new job instance.
     *
     * @param int|null $maxPages Max pages to fetch (null = all, 1 page ≈ 100 orders)
     */
    public function __construct(
        private readonly ?int $maxPages = null,
    ) {
        $this->onQueue('low');
    }

    /**
     * Seconds to wait before retrying.
     *
     * 1min, 5min, 1hr: quick retries catch transient issues, hour delay catches maintenance windows.
     * For micro syncs (every 5 min), the 1hr retry never fires — next schedule comes first.
     *
     * @var array<int>
     */
    public array $backoff = [60, 300, 3600];

    /**
     * Job timeout in seconds.
     *
     * Set to 70 minutes to accommodate full sync of all orders with buffer.
     */
    public int $timeout = 4200;

    /**
     * Execute the job.
     *
     * @throws ExternalServiceUnavailableException When ShopWired API unavailable - will retry
     * @throws InvalidApiResponseException When API contract violation (permanent failure)
     * @throws AuthenticationExpiredException When credentials invalid (permanent failure)
     * @throws Throwable When unexpected errors occur - indicates code update required
     */
    public function handle(SyncOrdersUseCase $useCase): void
    {
        $syncType = match (true) {
            $this->maxPages === null => 'full',
            $this->maxPages === 1 => 'micro',
            default => 'quick',
        };
        Log::info("ShopWired order sync job starting ({$syncType})", [
            'max_pages' => $this->maxPages,
        ]);

        try {
            $result = $useCase->execute($this->maxPages);

            Log::info('ShopWired order sync job completed', [
                'fetched' => $result->fetched,
                'saved' => $result->saved,
                'failed' => $result->failed,
            ]);
        } catch (InvalidApiResponseException $e) {
            // Permanent failure - API contract changed, code needs updating
            Log::critical('API response validation failed during ShopWired order sync', [
                'service' => $e->serviceName,
                'error' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            $this->fail($e);
            throw $e;
        } catch (AuthenticationExpiredException $e) {
            // Permanent failure - credentials need fixing, don't waste retries
            Log::critical('Authentication failed during ShopWired order sync', [
                'service' => $e->serviceName,
                'error' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            $this->fail($e);
            throw $e;
        } catch (ExternalServiceUnavailableException $e) {
            Log::warning('ShopWired API unavailable during order sync, will retry', [
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
            Log::critical('Unexpected exception in ShopWired order sync - code update required', [
                'job' => self::class,
                'exception' => $e::class,
                'message' => $e->getMessage(),
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
            'max_pages' => $this->maxPages,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
