<?php

declare(strict_types=1);

namespace App\Application\Linnworks\UseCases;

use App\Application\Contracts\Linnworks\PurchaseOrderClientInterface;
use App\Application\Contracts\Linnworks\PurchaseOrderSyncRepositoryInterface;
use App\Application\Linnworks\Enums\PurchaseOrderDepth;
use App\Application\Results\SyncResult;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderFull;
use App\Domain\ValueObjects\Guid;
use Psr\Log\LoggerInterface;

/**
 * Sync purchase orders using the Full (three-call) data model.
 *
 * Accepts pre-fetched IDs, fetches Full per ID (3 API calls), buffers results,
 * and flushes in batches via save(). Continues on per-PO save failures.
 *
 * Use for normal/full sync where complete data including notes and extended
 * properties is required.
 */
final readonly class SyncPurchaseOrderFullUseCase
{
    /**
     * Number of POs to accumulate before flushing to database.
     * Each PO is still saved individually (no batch write) — this controls
     * progress log frequency and memory pressure, not I/O batching.
     * Full sync is slower (3 API calls/PO), so a smaller checkpoint limits gaps.
     */
    private const int BUFFER_SIZE = 20;

    /**
     * Log progress every N batches at info level.
     */
    private const int PROGRESS_LOG_INTERVAL = 5;

    public function __construct(
        private PurchaseOrderClientInterface $purchaseOrderClient,
        private PurchaseOrderSyncRepositoryInterface $repository,
        private LoggerInterface $logger,
    ) {}

    /**
     * Sync purchase orders for the given IDs.
     *
     * @param list<Guid> $purchaseOrderIds Pre-fetched purchase order IDs to sync
     *
     * @throws AuthenticationExpiredException When Linnworks credentials invalid/expired
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws ResourceNotFoundException When a requested PO is not found
     * @throws ExternalServiceUnavailableException When Linnworks API or database unavailable
     * @throws InvalidApiResponseException When API response parsing fails
     */
    public function execute(array $purchaseOrderIds): SyncResult
    {
        if ($purchaseOrderIds === []) {
            $this->logger->info('Purchase order full sync skipped: no IDs provided');

            return SyncResult::empty();
        }

        $this->logger->info('Purchase order full sync starting', [
            'total_ids' => \count($purchaseOrderIds),
        ]);

        return $this->fetchAndSave($purchaseOrderIds);
    }

    /**
     * Iterate IDs, fetch Full data per ID, buffer, and flush in batches.
     *
     * @param list<Guid> $purchaseOrderIds
     *
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiRequestException
     * @throws InvalidApiResponseException
     * @throws ResourceNotFoundException
     */
    private function fetchAndSave(array $purchaseOrderIds): SyncResult
    {
        $totals = new PurchaseOrderSyncTotalsResult();
        /** @var list<PurchaseOrderFull> $buffer */
        $buffer = [];
        $batchesFlushed = 0;

        foreach ($purchaseOrderIds as $id) {
            $buffer[] = $this->fetchFull($id, \count($buffer));
            $totals->addFetched();

            if (\count($buffer) >= self::BUFFER_SIZE) {
                $this->flushBuffer($buffer, $batchesFlushed, $totals);
                [$buffer, $batchesFlushed] = [[], $batchesFlushed + 1];
                $this->logProgressIfDue($batchesFlushed, $totals);
            }
        }

        return $this->finalize($totals, $buffer, \count($purchaseOrderIds));
    }

    /**
     * Fetch a single Full PO and log debug info.
     *
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiRequestException
     * @throws InvalidApiResponseException
     * @throws ResourceNotFoundException
     */
    private function fetchFull(Guid $id, int $bufferSize): PurchaseOrderFull
    {
        $this->logger->debug('Fetching purchase order full', ['purchase_id' => $id->value]);
        $full = $this->purchaseOrderClient->getPurchaseOrder($id, PurchaseOrderDepth::Full);
        $this->logger->debug('Fetched purchase order full', [
            'purchase_id' => $id->value,
            'buffer_size' => $bufferSize + 1,
        ]);

        return $full;
    }

    /**
     * Flush any remaining buffer and build the final SyncResult.
     *
     * @param list<PurchaseOrderFull> $buffer
     *
     * @throws ExternalServiceUnavailableException
     */
    private function finalize(PurchaseOrderSyncTotalsResult $totals, array $buffer, int $totalIds): SyncResult
    {
        $this->flushBuffer($buffer, 'final', $totals);

        $this->logger->info('Purchase order full sync completed', [
            'total_ids' => $totalIds,
            ...$totals->toLogContext(),
        ]);

        return $totals->toSyncResult();
    }

    /**
     * Flush a buffer of Full POs to the database with continue-on-failure.
     *
     * @param list<PurchaseOrderFull> $buffer
     *
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    private function flushBuffer(array $buffer, int|string $batchIdentifier, PurchaseOrderSyncTotalsResult $totals): void
    {
        if ($buffer === []) {
            return;
        }

        $this->logger->debug('Flushing purchase order full batch', [
            'batch' => $batchIdentifier,
            'count' => \count($buffer),
        ]);

        foreach ($buffer as $full) {
            $this->saveSingleFull($full, $batchIdentifier, $totals);
        }
    }

    /**
     * Save a single Full PO, continuing on DB failures.
     *
     * Transient failures (ExternalServiceUnavailableException) are rethrown
     * immediately — they indicate the database is down and retrying won't help.
     *
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    private function saveSingleFull(PurchaseOrderFull $full, int|string $batchIdentifier, PurchaseOrderSyncTotalsResult $totals): void
    {
        try {
            $this->repository->save($full);
            $totals->addSaved();
        } catch (ExternalServiceUnavailableException $e) {
            throw $e;
        } catch (DatabaseOperationFailedException|DuplicateRecordException $e) {
            $ref = $full->core->header->pkPurchaseId->value;
            $totals->addFailed($ref);
            $this->logger->error('Failed to save purchase order full', [
                'batch' => $batchIdentifier,
                'purchase_id' => $ref,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Log progress at info level every N batches.
     */
    private function logProgressIfDue(int $batchesFlushed, PurchaseOrderSyncTotalsResult $totals): void
    {
        if ($batchesFlushed % self::PROGRESS_LOG_INTERVAL === 0) {
            $this->logger->info('Purchase order full sync progress', [
                'batches_flushed' => $batchesFlushed,
                ...$totals->toLogContext(),
            ]);
        }
    }
}
