<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Services;

use App\Application\Contracts\QuickBooks\OrderSyncDispatcherInterface;
use App\Application\Contracts\QuickBooks\OrderSyncRunRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderChunkRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderRangeRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\RequestedOrderSyncRunDTO;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Throwable;

/**
 * Opens a run for any entry point: records it, then queues its first job.
 */
final readonly class OrderSyncRunService
{
    public function __construct(
        private OrderSyncRunRepositoryInterface $runs,
        private OrderSyncDispatcherInterface $dispatcher,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws Throwable what queueing the first job threw, once the run is ended failed
     */
    public function open(RequestedOrderSyncRunDTO $run, OrderRangeRunDTO|OrderChunkRunDTO $firstJob): void
    {
        $this->runs->record($run);

        try {
            if ($firstJob instanceof OrderRangeRunDTO) {
                $this->dispatcher->dispatchRange($firstJob);
            } else {
                $this->dispatcher->dispatchChunk($firstJob);
            }
        } catch (Throwable $e) {
            $this->runs->failRequest($run->id);

            throw $e;
        }
    }
}
