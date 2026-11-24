<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks\Dispatchers;

use App\Application\Contracts\QuickBooks\OrderSyncDispatcherInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderChunkRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderRangeRunDTO;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\Jobs\QuickBooks\SyncQuickBooksOrderChunkJob;
use App\Infrastructure\Jobs\QuickBooks\SyncQuickBooksOrderRangeJob;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Collection;
use Override;

/**
 * A copy waits a random time, so a run's copies do not come back together.
 */
final readonly class QueuedOrderSyncDispatcher implements OrderSyncDispatcherInterface
{
    public const int MIN_DELAY_SECONDS = 20;

    public const int MAX_DELAY_SECONDS = 40;

    /**
     * @throws InvalidConfigurationException when no chunk could ever take a slot
     */
    public function __construct(
        private QueueFactory $queue,
        int $concurrency,
    ) {
        if ($concurrency < 1) {
            throw new InvalidConfigurationException('QUICKBOOKS_ORDER_SYNC_CONCURRENCY', 'Expected a whole number of at least 1');
        }
    }

    #[Override]
    public function dispatchRange(OrderRangeRunDTO $run): void
    {
        SyncQuickBooksOrderRangeJob::dispatch($run);
    }

    #[Override]
    public function dispatchChunk(OrderChunkRunDTO $run): void
    {
        if ($run->attempt > 1) {
            self::dispatchDelayed($run);

            return;
        }

        SyncQuickBooksOrderChunkJob::dispatch($run);
    }

    /**
     * Queues the same attempt again for when a slot may be free.
     *
     * @throws InvalidConfigurationException when the queue ignores delays, so the wait would loop in this process
     */
    public function dispatchWaiting(OrderChunkRunDTO $run): void
    {
        if ($this->queue->connection() instanceof SyncQueue) {
            throw new InvalidConfigurationException('QUEUE_CONNECTION', 'Waiting for a slot needs a queue that keeps delays');
        }

        self::dispatchDelayed($run);
    }

    private static function dispatchDelayed(OrderChunkRunDTO $run): void
    {
        SyncQuickBooksOrderChunkJob::dispatch($run)->delay(new Collection(\range(self::MIN_DELAY_SECONDS, self::MAX_DELAY_SECONDS))->random());
    }
}
