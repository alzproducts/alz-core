<?php

declare(strict_types=1);

namespace App\Application\Jobs\Inventory;

use App\Application\Inventory\UseCases\SyncDeltaStockToShopwiredUseCase;
use App\Application\Jobs\Enums\QueueName;
use App\Domain\Exceptions\Api\PermanentApiFailure;
use App\Domain\Exceptions\Api\TransientApiFailure;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduled job: delta Linnworks → ShopWired stock sync.
 *
 * Queries Linnworks for SKUs changed since the last cursor and pushes only
 * the differences to ShopWired. Fast, incremental path for near-real-time
 * stock accuracy. The full sync job handles any drift this misses.
 *
 * Frequency: every 5 minutes.
 *
 * @see SyncDeltaStockToShopwiredUseCase
 */
final class SyncDeltaStockToShopwiredJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    /** @var array<int> */
    public array $backoff = [30];

    public int $timeout = 60;

    /**
     * Unique for 5 minutes — matches the schedule frequency.
     */
    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return 'sync-delta-stock-to-shopwired';
    }

    public function __construct()
    {
        $this->onQueue(QueueName::Default->value);
    }

    /**
     * Execute the job.
     *
     * @throws TransientApiFailure When an API is unavailable (triggers retry)
     * @throws LockAcquisitionException When the sync lock is held by a concurrent run (triggers retry)
     * @throws PermanentApiFailure When a permanent API failure occurs (fails immediately)
     * @throws DatabaseOperationFailedException When a local DB operation fails permanently (fails immediately)
     * @throws DuplicateRecordException When a unique constraint is violated (fails immediately)
     * @throws Throwable When an unexpected error occurs — indicates a code issue
     */
    public function handle(SyncDeltaStockToShopwiredUseCase $useCase): void
    {
        try {
            $useCase->execute();
        } catch (LockAcquisitionException $e) {
            // Transient — full sync holds the lock. Do NOT call fail(); let Laravel's backoff retry.
            Log::warning('Delta stock sync job: could not acquire lock, will retry', [
                'lock' => $e->lockName,
                'timeout' => $e->timeoutSeconds,
                'attempts' => $this->attempts(),
            ]);
            throw $e;
        } catch (TransientApiFailure $e) {
            Log::warning('Delta stock sync job: service unavailable, will retry', [
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
            Log::error('Delta stock sync job: permanent API failure', [
                'service' => $e->serviceName,
                'message' => $e->getMessage(),
            ]);
            $this->fail($e);
            throw $e;
        } catch (DatabaseOperationFailedException|DuplicateRecordException $e) {
            Log::error('Delta stock sync job: database failure', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $this->fail($e);
            throw $e;
        } catch (Throwable $e) {
            Log::critical('Delta stock sync job: unexpected error', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
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
        Log::error('Delta stock sync job failed permanently', [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
