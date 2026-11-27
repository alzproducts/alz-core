<?php

declare(strict_types=1);

namespace App\Application\Contracts\QuickBooks;

use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncRunCountsDTO;
use App\Application\QuickBooks\OrderSync\DTOs\RequestedOrderSyncRunDTO;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

/**
 * A run ends when its open-job tally reaches zero (docs/architecture/quickbooks-order-sync.md). Every write is one
 * atomic update, as a run's jobs end in parallel. A run id with no row, or none at all, writes nothing.
 */
interface OrderSyncRunRepositoryInterface
{
    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function record(RequestedOrderSyncRunDTO $run): void;

    /**
     * Counts one job of the run as open; called before the job is queued.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function addOpenJob(?string $runId): void;

    /**
     * Takes back an open job that was never queued, without ending the run.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function removeOpenJob(?string $runId): void;

    /**
     * Marks a queued run running; a run already started keeps its start time.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function markStarted(?string $runId): void;

    /**
     * Ends one open job: adds its counts and, when it was the last, ends the run.
     *
     * @param bool $failed whether the job failed outright, which fails the run
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function endJob(?string $runId, OrderSyncRunCountsDTO $counts, bool $failed): void;

    /**
     * Ends the range job, setting the run's candidate count to the references it dispatched.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function endRangeJob(?string $runId, int $candidateCount): void;

    /**
     * Adds counts without ending the job, which still ends through its failure handler.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function addCounts(?string $runId, OrderSyncRunCountsDTO $counts, bool $failed): void;

    /**
     * Ends a run whose first job could not be queued.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function failRequest(string $runId): void;
}
