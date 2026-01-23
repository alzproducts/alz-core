<?php

declare(strict_types=1);

namespace App\Presentation\Jobs\Shopwired;

use App\Application\Shopwired\UseCases\SyncCustomersUseCase;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asynchronously synchronize ShopWired customers to local database.
 *
 * Supports full sync, quick sync, and micro sync modes with page limits.
 *
 * Usage:
 * - Full sync: SyncShopwiredCustomersJob::dispatch() — daily, ~45 min
 * - Quick sync: SyncShopwiredCustomersJob::dispatch(5, 5) — hourly, ~2 min
 * - Micro sync: SyncShopwiredCustomersJob::dispatch(1, 1) — every 5 min, ~30s
 */
final class SyncShopwiredCustomersJob implements ShouldQueue
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
     * @param int|null $maxTradePages Max trade pages (null = all ~5 pages, 1 page ≈ 100 customers)
     * @param int|null $maxNonTradePages Max non-trade pages (null = all ~677 pages, 1 page ≈ 100 customers)
     */
    public function __construct(
        private readonly ?int $maxTradePages = null,
        private readonly ?int $maxNonTradePages = null,
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
     * Set to 70 minutes to accommodate full sync of ~68k customers with buffer.
     * Actual runtime observed: ~46-60 minutes for 67,717 customers.
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
    public function handle(SyncCustomersUseCase $useCase): void
    {
        $syncType = match (true) {
            $this->maxTradePages === null && $this->maxNonTradePages === null => 'full',
            $this->maxTradePages === 1 && $this->maxNonTradePages === 1 => 'micro',
            default => 'quick',
        };
        Log::info("ShopWired customer sync job starting ({$syncType})", [
            'max_trade_pages' => $this->maxTradePages,
            'max_non_trade_pages' => $this->maxNonTradePages,
        ]);

        try {
            $result = $useCase->execute($this->maxTradePages, $this->maxNonTradePages);

            Log::info('ShopWired customer sync job completed', [
                'fetched' => $result->fetched,
                'saved' => $result->saved,
                'failed' => $result->failed,
            ]);
        } catch (InvalidApiResponseException $e) {
            // Permanent failure - API contract changed, code needs updating
            Log::critical('API response validation failed during ShopWired customer sync', [
                'service' => $e->serviceName,
                'error' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            $this->fail($e);
            throw $e;
        } catch (AuthenticationExpiredException $e) {
            // Permanent failure - credentials need fixing, don't waste retries
            Log::critical('Authentication failed during ShopWired customer sync', [
                'service' => $e->serviceName,
                'error' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            $this->fail($e);
            throw $e;
        } catch (ExternalServiceUnavailableException $e) {
            Log::warning('ShopWired API unavailable during customer sync, will retry', [
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
            Log::critical('Unexpected exception in ShopWired customer sync - code update required', [
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
        Log::error('ShopWired customer sync job failed permanently', [
            'max_trade_pages' => $this->maxTradePages,
            'max_non_trade_pages' => $this->maxNonTradePages,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
