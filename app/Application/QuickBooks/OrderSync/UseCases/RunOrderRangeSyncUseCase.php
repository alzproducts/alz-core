<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\UseCases;

use App\Application\Contracts\QuickBooks\OrderSyncDispatcherInterface;
use App\Application\Contracts\QuickBooks\OrderSyncRunRepositoryInterface;
use App\Application\Contracts\QuickBooks\OrderSyncSourceQueryRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderChunkRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderRangeRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncChunkDTO;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;
use App\Application\QuickBooks\OrderSync\Resolvers\OrderSyncCalendarResolver;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * Splits a range into chunks. The row-state rules are the chunk's, so every reference in the range is dispatched.
 */
final readonly class RunOrderRangeSyncUseCase
{
    public function __construct(
        private OrderSyncSourceQueryRepositoryInterface $sourceOrders,
        private OrderSyncDispatcherInterface $dispatcher,
        private OrderSyncRunRepositoryInterface $runs,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(OrderRangeRunDTO $run): void
    {
        $this->logger->info('Running QuickBooks order range sync', ['run_id' => $run->runId]);

        $this->runs->markStarted($run->runId);
        $references = $this->sourceOrders->referencesPlacedIn(...OrderSyncCalendarResolver::rangeBounds($run->range->from, $run->range->to));
        $chunks = \array_chunk($references, OrderSyncChunkDTO::MAX_REFERENCES);
        foreach ($chunks as $chunk) {
            $this->dispatcher->dispatchChunk(new OrderChunkRunDTO(
                new OrderSyncChunkDTO($run->realmId, $chunk, $run->forceRetry, OrderSyncTrigger::Range, $run->runId),
            ));
        }

        $this->runs->endRangeJob($run->runId, \count($references));

        $this->logger->info('Queued QuickBooks order chunks', ['run_id' => $run->runId, 'chunks' => \count($chunks)]);
    }
}
