<?php

declare(strict_types=1);

namespace App\Application\Jobs\Inventory;

use App\Application\Inventory\UseCases\SyncFullStockToShopwiredUseCase;
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
 * Scheduled job: full Linnworks → ShopWired stock sync.
 *
 * Fetches all stock from Linnworks, compares against the local ShopWired DB
 * snapshot, and pushes any differences. Acts as a safety net to catch drift
 * that the delta sync may miss (e.g., order lock/unlock changes).
 *
 * Frequency: every 15 minutes.
 *
 * @see SyncFullStockToShopwiredUseCase
 */
final class SyncFullStockToShopwiredJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    /** @var array<int> */
    public array $backoff = [60];

    public int $timeout = 120;

    /**
     * Unique for 15 minutes — matches the schedule frequency.
     */
    public int $uniqueFor = 900;

    public function uniqueId(): string
    {
        return 'sync-full-stock-to-shopwired';
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
    public function handle(SyncFullStockToShopwiredUseCase $useCase): void
    {
        try {
            $useCase->execute();
        } catch (LockAcquisitionException $e) {
            // Transient — delta sync holds the lock. Do NOT call fail(); let Laravel's backoff retry.
            Log::warning('Full stock sync job: could not acquire lock, will retry', [
                'lock' => $e->lockName,
                'timeout' => $e->timeoutSeconds,
                'attempts' => $this->attempts(),
            ]);
            throw $e;
        } catch (TransientApiFailure $e) {
            Log::warning('Full stock sync job: service unavailable, will retry', [
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
            Log::error('Full stock sync job: permanent API failure', [
                'service' => $e->serviceName,
                'message' => $e->getMessage(),
            ]);
            $this->fail($e);
            throw $e;
        } catch (DatabaseOperationFailedException|DuplicateRecordException $e) {
            Log::error('Full stock sync job: database failure', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $this->fail($e);
            throw $e;
        } catch (Throwable $e) {
            Log::critical('Full stock sync job: unexpected error', [
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
        Log::error('Full stock sync job failed permanently', [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
