<?php

declare(strict_types=1);

namespace App\Application\Jobs\Shopwired;

use App\Application\Shopwired\UseCases\SyncProductsUseCase;
use App\Domain\Exceptions\Api\PermanentApiFailure;
use App\Domain\Exceptions\Api\TransientApiFailure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asynchronously synchronize ShopWired products to local database.
 *
 * Performs full catalog sync only. ShopWired Products API doesn't support
 * date-based sorting, making incremental sync impractical.
 *
 * Usage:
 * - Full sync: SyncShopwiredProductsJob::dispatch() — daily, ~2-5 min
 */
final class SyncShopwiredProductsJob implements ShouldQueue
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
     */
    public function __construct()
    {
        $this->onQueue('low');
    }

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
     * Execute the job.
     *
     * @throws TransientApiFailure When ShopWired API unavailable (triggers retry)
     * @throws PermanentApiFailure When permanent API failure occurs (fails immediately)
     * @throws Throwable When unexpected errors occur - indicates code update required
     */
    public function handle(SyncProductsUseCase $useCase): void
    {
        Log::info('ShopWired product sync job starting');

        try {
            $result = $useCase->execute();

            Log::info('ShopWired product sync job completed', [
                'fetched' => $result->fetched,
                'saved' => $result->saved,
                'failed' => $result->failed,
            ]);
        } catch (TransientApiFailure $e) {
            Log::warning('ShopWired product sync service unavailable, will retry', [
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
            Log::critical('ShopWired product sync permanent API failure, failing immediately', [
                'exception' => $e::class,
                'service' => $e->serviceName,
                'error' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            $this->fail($e);
            throw $e;
        } catch (Throwable $e) {
            // Unexpected exception = code needs updating
            Log::critical('Unexpected exception in ShopWired product sync - code update required', [
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
        Log::error('ShopWired product sync job failed permanently', [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
