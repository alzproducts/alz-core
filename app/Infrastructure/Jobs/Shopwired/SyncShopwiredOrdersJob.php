<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Infrastructure\Jobs\Enums\QueueName;
use App\Application\Shopwired\UseCases\SyncOrdersUseCase;
use App\Domain\Exceptions\Api\AbstractApiException;
use App\Domain\Exceptions\Api\PermanentApiFailure;
use App\Domain\Exceptions\Api\TransientApiFailure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Asynchronously synchronize ShopWired orders to local database.
 *
 * Supports full sync and quick sync modes with page limits.
 *
 * Usage:
 * - Full sync: SyncShopwiredOrdersJob::dispatch() — monthly, all orders
 * - Quick sync: SyncShopwiredOrdersJob::dispatch(5) — every 6 hours, ~500 orders
 *
 * @see SyncShopwiredOrdersRangeJob For date-range based sync
 */
final class SyncShopwiredOrdersJob implements ShouldBeUnique, ShouldQueue
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
     * Maximum exceptions allowed before failing.
     */
    public int $maxExceptions = 3;

    /**
     * Unique lock duration in seconds.
     *
     * Set to max expected runtime + buffer. If job completes sooner,
     * lock releases immediately. If job times out, lock auto-releases.
     */
    public int $uniqueFor = 10000;

    /**
     * Get the unique ID for this job.
     *
     * Returns a fixed ID (ignores constructor params) so ALL sync modes
     * (full/quick) share one lock. This prevents quick syncs from
     * running while a full sync is in progress.
     */
    public function uniqueId(): string
    {
        return 'sync-shopwired-orders';
    }

    /**
     * Create a new job instance.
     *
     * @param int|null $maxPages Max pages to fetch (null = all, 1 page ≈ 100 orders)
     */
    public function __construct(
        private readonly ?int $maxPages = null,
    ) {
        $this->onQueue(QueueName::Low->value);
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
     * Set to 2.5 hours to accommodate full sync of all orders with buffer.
     */
    public int $timeout = 9000;

    /**
     * Execute the job.
     *
     * @throws TransientApiFailure When ShopWired API unavailable (triggers retry)
     * @throws PermanentApiFailure When permanent API failure occurs (fails immediately)
     * @throws Throwable When unexpected errors occur - indicates code update required
     */
    public function handle(SyncOrdersUseCase $useCase, LoggerInterface $logger): void
    {
        $syncType = $this->maxPages === null ? 'full' : 'quick';
        $logger->info("ShopWired order sync job starting ({$syncType})", [
            'max_pages' => $this->maxPages,
        ]);

        try {
            $result = $useCase->execute($this->maxPages);

            $logger->info('ShopWired order sync job completed', [
                'fetched' => $result->fetched,
                'saved' => $result->saved,
                'failed' => $result->failed,
            ]);
        } catch (TransientApiFailure $e) {
            // Dual retry: API-provided delay via release(), or Laravel backoff via rethrow
            $logger->warning('ShopWired order sync service unavailable, will retry', [
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
            'max_pages' => $this->maxPages,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ];

        if ($exception instanceof AbstractApiException) {
            Log::error('ShopWired order sync job failed permanently', $context);
        } else {
            Log::critical('ShopWired order sync job failed permanently', $context);
        }
    }
}
