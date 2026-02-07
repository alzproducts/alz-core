<?php

declare(strict_types=1);

namespace App\Application\Jobs\Shopwired;

use App\Application\Shopwired\UseCases\ReconcileProductsUseCase;
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
 * Asynchronously reconcile ShopWired products (remove orphans).
 *
 * Compares local product IDs against ShopWired API and removes any
 * that no longer exist in ShopWired.
 *
 * Usage:
 * - Reconciliation: ReconcileShopwiredProductsJob::dispatch() — daily overnight
 *
 * Schedule: Run after main sync completes to ensure local data is current.
 */
final class ReconcileShopwiredProductsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Maximum number of attempts before giving up.
     */
    public int $tries = 3;

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
     * Lightweight job (ID comparison only), so short delays.
     *
     * @var array<int>
     */
    public array $backoff = [30, 60, 120];

    /**
     * Job timeout in seconds.
     *
     * Set to 5 minutes for lightweight ID comparison.
     */
    public int $timeout = 300;

    /**
     * Execute the job.
     *
     * @throws TransientApiFailure When ShopWired API unavailable (triggers retry)
     * @throws PermanentApiFailure When permanent API failure occurs (fails immediately)
     * @throws Throwable When unexpected errors occur - indicates code update required
     */
    public function handle(ReconcileProductsUseCase $useCase): void
    {
        Log::info('ShopWired product reconciliation job starting');

        try {
            $result = $useCase->execute();

            if ($result->wasSkipped()) {
                Log::warning('ShopWired product reconciliation job skipped (safety check)', [
                    'local_count' => $result->localCount,
                ]);

                return;
            }

            Log::info('ShopWired product reconciliation job completed', [
                'api_count' => $result->apiCount,
                'local_count' => $result->localCount,
                'orphans_found' => $result->orphansFound,
                'orphans_deleted' => $result->orphansDeleted,
            ]);
        } catch (TransientApiFailure $e) {
            Log::warning('ShopWired product reconciliation service unavailable, will retry', [
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
            Log::critical('ShopWired product reconciliation permanent API failure, failing immediately', [
                'exception' => $e::class,
                'service' => $e->serviceName,
                'error' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            $this->fail($e);
            throw $e;
        } catch (Throwable $e) {
            // Unexpected exception = code needs updating
            Log::critical('Unexpected exception in ShopWired product reconciliation - code update required', [
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
        Log::error('ShopWired product reconciliation job failed permanently', [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
