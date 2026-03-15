<?php

declare(strict_types=1);

namespace App\Application\Jobs\Shopwired;

use App\Application\Jobs\Enums\QueueName;
use App\Application\Shopwired\UseCases\SyncProductsUseCase;
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
 * Asynchronously synchronize ShopWired products to local database.
 *
 * Performs full catalog sync only. ShopWired Products API doesn't support
 * date-based sorting, making incremental sync impractical.
 *
 * Usage:
 * - Full sync: SyncShopwiredProductsJob::dispatch() — monthly (first Sunday), ~2-5 min
 */
final class SyncShopwiredProductsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Maximum number of attempts before giving up.
     */
    public int $tries = 5;

    /**
     * Seconds to wait before retrying (exponential backoff).
     *
     * Shorter delays than customers/orders due to faster runtime (~2-5 min).
     *
     * @var array<int>
     */
    public array $backoff = [30, 60, 120, 240];

    /**
     * Job timeout in seconds.
     *
     * Set to 15 minutes to accommodate full sync of ~1,500 products.
     */
    public int $timeout = 900;

    /**
     * Seconds this job should remain unique.
     */
    public int $uniqueFor = 1200;

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return 'sync-shopwired-products';
    }

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue(QueueName::Low->value);
    }

    /**
     * Execute the job.
     *
     * @throws TransientApiFailure When ShopWired API unavailable (triggers retry)
     * @throws PermanentApiFailure When permanent API failure occurs (fails immediately)
     * @throws Throwable When unexpected errors occur - indicates code update required
     */
    public function handle(SyncProductsUseCase $useCase, LoggerInterface $logger): void
    {
        $logger->info('ShopWired product sync job starting');

        try {
            $result = $useCase->execute();

            $logger->info('ShopWired product sync job completed', [
                'fetched' => $result->fetched,
                'saved' => $result->saved,
                'failed' => $result->failed,
            ]);
        } catch (TransientApiFailure $e) {
            // Dual retry: API-provided delay via release(), or Laravel backoff via rethrow
            $logger->warning('ShopWired product sync service unavailable, will retry', [
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
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ];

        if ($exception instanceof AbstractApiException) {
            Log::error('ShopWired product sync job failed permanently', $context);
        } else {
            Log::critical('ShopWired product sync job failed permanently', $context);
        }
    }
}
