<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks\Dispatchers;

use App\Application\Contracts\QuickBooks\OrderSyncDispatcherInterface;
use App\Application\Contracts\QuickBooks\OrderSyncRunRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderChunkRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderRangeRunDTO;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\Jobs\QuickBooks\SyncQuickBooksOrderChunkJob;
use App\Infrastructure\Jobs\QuickBooks\SyncQuickBooksOrderRangeJob;
use Closure;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Collection;
use Override;
use Throwable;

/**
 * A copy waiting for a slot waits a random time, so a run's copies do not come back together. A re-attempt waits
 * long enough for QuickBooks to recover from what stopped the last one.
 *
 * Each queued job counts as open on its run before it is pushed, so a job that ends at once cannot see the run
 * finish early, and a push that fails takes its count back.
 */
final readonly class QueuedOrderSyncDispatcher implements OrderSyncDispatcherInterface
{
    public const int MIN_DELAY_SECONDS = 20;

    public const int MAX_DELAY_SECONDS = 40;

    public const int REATTEMPT_DELAY_SECONDS = 600;

    /**
     * @throws InvalidConfigurationException when no chunk could ever take a slot
     */
    public function __construct(
        private QueueFactory $queue,
        private OrderSyncRunRepositoryInterface $runs,
        int $concurrency,
    ) {
        if ($concurrency < 1) {
            throw new InvalidConfigurationException('QUICKBOOKS_ORDER_SYNC_CONCURRENCY', 'Expected a whole number of at least 1');
        }
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function dispatchRange(OrderRangeRunDTO $run): void
    {
        $this->counted($run->runId, static function () use ($run): void {
            SyncQuickBooksOrderRangeJob::dispatch($run);
        });
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function dispatchChunk(OrderChunkRunDTO $run): void
    {
        $this->counted($run->chunk->runId, static function () use ($run): void {
            if ($run->attempt > 1) {
                SyncQuickBooksOrderChunkJob::dispatch($run)->delay(self::REATTEMPT_DELAY_SECONDS);

                return;
            }

            SyncQuickBooksOrderChunkJob::dispatch($run);
        });
    }

    /**
     * Queues the same attempt again for when a slot may be free.
     *
     * intentional: no open-job count, as the waiting copy replaces the job that queues it and that job never ends.
     *
     * @throws InvalidConfigurationException when the queue ignores delays, so the wait would loop in this process
     */
    public function dispatchWaiting(OrderChunkRunDTO $run): void
    {
        if ($this->queue->connection() instanceof SyncQueue) {
            throw new InvalidConfigurationException('QUEUE_CONNECTION', 'Waiting for a slot needs a queue that keeps delays');
        }

        SyncQuickBooksOrderChunkJob::dispatch($run)->delay(new Collection(\range(self::MIN_DELAY_SECONDS, self::MAX_DELAY_SECONDS))->random());
    }

    /**
     * @param Closure(): void $push
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function counted(?string $runId, Closure $push): void
    {
        $this->runs->addOpenJob($runId);

        try {
            $push();
        } catch (Throwable $e) {
            $this->runs->removeOpenJob($runId);

            throw $e;
        }
    }
}
