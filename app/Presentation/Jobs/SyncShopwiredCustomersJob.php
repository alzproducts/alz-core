<?php

declare(strict_types=1);

namespace App\Presentation\Jobs;

use App\Application\Shopwired\UseCases\SyncCustomersUseCase;
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
 * Asynchronously synchronize ShopWired customers to local database.
 *
 * Supports both full sync (all customers) and quick sync (recent customers only).
 * Always syncs ALL trade customers (B2B priority), with optional limit on non-trade pages.
 *
 * Usage:
 * - Full sync: SyncShopwiredCustomersJob::dispatch() — daily, ~45 min
 * - Quick sync: SyncShopwiredCustomersJob::dispatch(5) — hourly, ~1 min (5 non-trade pages)
 */
final class SyncShopwiredCustomersJob implements ShouldQueue
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
     * Create a new job instance.
     *
     * @param int|null $maxNonTradePages Max non-trade pages (null = all, 1 page ≈ 100 customers)
     */
    public function __construct(
        private readonly ?int $maxNonTradePages = null,
    ) {
        $this->onQueue('low');
    }

    /**
     * Seconds to wait before retrying (exponential backoff).
     *
     * Longer delays than orders due to longer runtime - failed retries are costly.
     *
     * @var array<int>
     */
    public array $backoff = [120, 300, 600, 1200, 2400];

    /**
     * Job timeout in seconds.
     *
     * Set to 60 minutes to accommodate full sync of ~68k customers.
     * Actual runtime observed: ~46 minutes for 67,717 customers.
     */
    public int $timeout = 3600;

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
        $syncType = $this->maxNonTradePages === null ? 'full' : 'quick';
        Log::info("ShopWired customer sync job starting ({$syncType})", [
            'max_non_trade_pages' => $this->maxNonTradePages,
        ]);

        try {
            $result = $useCase->execute($this->maxNonTradePages);

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
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
