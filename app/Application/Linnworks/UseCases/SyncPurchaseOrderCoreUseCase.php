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
 * assembles PurchaseOrderCore objects in PHP, and persists via saveCore().
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
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws ResourceNotFoundException When a requested resource is not found
     * @throws ExternalServiceUnavailableException When Linnworks API or database unavailable
     * @throws InvalidApiResponseException When API response parsing fails
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
     * Assemble Core VOs from batch data and persist each.
     *
     * @param array<string, array{header: PurchaseOrderHeader, noteCount: int}> $headerData
     * @param array<string, list<PurchaseOrderItem>>                            $itemsByPo
     *
     * @throws ExternalServiceUnavailableException
     */
    private function assembleAndSave(array $headerData, array $itemsByPo): PurchaseOrderSyncTotalsResult
    {
        $totals = new PurchaseOrderSyncTotalsResult();

        foreach ($headerData as $purchaseId => $data) {
            $core = new PurchaseOrderCore(
                header: $data['header'],
                noteCount: $data['noteCount'],
                items: $itemsByPo[$purchaseId] ?? [],
            );

            $totals->addFetched();
            $this->saveSingleCore($core, $totals);
        }

        return $totals;
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

    /**
     * Save a single Core PO, continuing on DB failures.
     *
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    private function saveSingleCore(PurchaseOrderCore $core, PurchaseOrderSyncTotalsResult $totals): void
    {
        try {
            $this->repository->saveCore($core);
            $totals->addSaved();
        } catch (ExternalServiceUnavailableException $e) {
            throw $e;
        } catch (DatabaseOperationFailedException|DuplicateRecordException $e) {
            $ref = $core->header->pkPurchaseId->value;
            $totals->addFailed($ref);
            $this->logger->error('Failed to save purchase order core', [
                'purchase_id' => $ref,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
