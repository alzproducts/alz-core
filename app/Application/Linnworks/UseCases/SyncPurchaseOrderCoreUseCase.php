<?php

declare(strict_types=1);

namespace App\Application\Linnworks\UseCases;

use App\Application\Contracts\Linnworks\PurchaseDashboardsClientInterface;
use App\Application\Contracts\Linnworks\PurchaseOrderSyncRepositoryInterface;
use App\Application\Results\SyncResult;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderCore;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderHeader;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderItem;
use App\Domain\ValueObjects\Guid;
use Psr\Log\LoggerInterface;

/**
 * Sync purchase orders using batch SQL queries via the Dashboards API.
 *
 * Fetches all headers and items in 2 SQL queries (regardless of PO count),
 * assembles PurchaseOrderCore objects in PHP, and persists via saveCoresBatch().
 *
 * Use for fast sync (OPEN/PENDING/PARTIAL polling) where notes, extended
 * properties, additional costs, and delivered records are not required.
 */
final readonly class SyncPurchaseOrderCoreUseCase
{
    public function __construct(
        private PurchaseDashboardsClientInterface $dashboardsClient,
        private PurchaseOrderSyncRepositoryInterface $repository,
        private LoggerInterface $logger,
    ) {}

    /**
     * Sync purchase orders for the given IDs.
     *
     * @param list<Guid> $purchaseOrderIds Pre-fetched purchase order IDs to sync
     *
     * @throws AuthenticationExpiredException When Linnworks credentials invalid/expired
     * @throws DatabaseOperationFailedException When database write fails
     * @throws DuplicateRecordException When a duplicate record is encountered
     * @throws ExternalServiceUnavailableException When Linnworks API or database unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response parsing fails
     * @throws ResourceNotFoundException When a requested resource is not found
     */
    public function execute(array $purchaseOrderIds): SyncResult
    {
        if ($purchaseOrderIds === []) {
            $this->logger->info('Purchase order core sync skipped: no IDs provided');

            return SyncResult::empty();
        }

        $this->logger->info('Purchase order core sync starting', [
            'total_ids' => \count($purchaseOrderIds),
        ]);

        return $this->fetchAndSave($purchaseOrderIds);
    }

    /**
     * Fetch all data in 2 batch SQL queries, assemble Core VOs, and persist.
     *
     * @param list<Guid> $purchaseOrderIds
     *
     * @throws AuthenticationExpiredException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiRequestException
     * @throws InvalidApiResponseException
     * @throws ResourceNotFoundException
     */
    private function fetchAndSave(array $purchaseOrderIds): SyncResult
    {
        [$headerData, $itemsByPo] = $this->fetchBatchData($purchaseOrderIds);
        $totals = $this->assembleAndSave($headerData, $itemsByPo);

        $this->logger->info('Purchase order core sync completed', [
            'total_ids' => \count($purchaseOrderIds),
            ...$totals->toLogContext(),
        ]);

        return $totals->toSyncResult();
    }

    /**
     * @param array<string, array{header: PurchaseOrderHeader, noteCount: int}> $headerData
     * @param array<string, list<PurchaseOrderItem>>                            $itemsByPo
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function assembleAndSave(array $headerData, array $itemsByPo): PurchaseOrderSyncTotalsResult
    {
        $cores = self::assembleCores($headerData, $itemsByPo);
        $this->repository->saveCoresBatch($cores);
        $count = \count($cores);

        return PurchaseOrderSyncTotalsResult::fromBatch(fetched: $count, saved: $count);
    }

    /**
     * Assemble PurchaseOrderCore VOs from batch SQL data.
     *
     * @param array<string, array{header: PurchaseOrderHeader, noteCount: int}> $headerData
     * @param array<string, list<PurchaseOrderItem>>                            $itemsByPo
     *
     * @return list<PurchaseOrderCore>
     */
    private static function assembleCores(array $headerData, array $itemsByPo): array
    {
        $cores = [];

        foreach ($headerData as $purchaseId => $data) {
            $cores[] = new PurchaseOrderCore(
                header: $data['header'],
                noteCount: $data['noteCount'],
                items: $itemsByPo[$purchaseId] ?? [],
            );
        }

        return $cores;
    }

    /**
     * Fetch headers and items in 2 batch SQL queries.
     *
     * @param list<Guid> $purchaseOrderIds
     *
     * @return array{0: array<string, array{header: PurchaseOrderHeader, noteCount: int}>, 1: array<string, list<PurchaseOrderItem>>}
     *
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiRequestException
     * @throws InvalidApiResponseException
     * @throws ResourceNotFoundException
     */
    private function fetchBatchData(array $purchaseOrderIds): array
    {
        $headerData = $this->dashboardsClient->getPurchaseOrderHeadersBatch($purchaseOrderIds);
        $itemsByPo = $this->dashboardsClient->getPurchaseOrderItemsBatch($purchaseOrderIds);

        $this->logger->info('Purchase order core sync fetched batch data', [
            'headers' => \count($headerData),
            'item_groups' => \count($itemsByPo),
        ]);

        return [$headerData, $itemsByPo];
    }

}
