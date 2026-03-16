<?php

declare(strict_types=1);

namespace App\Application\Linnworks\UseCases;

use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Application\Contracts\Linnworks\SupplierRepositoryInterface;
use App\Application\Results\SyncResult;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Inventory\ValueObjects\Supplier;
use Psr\Log\LoggerInterface;

/**
 * Orchestrate supplier directory synchronization from Linnworks API to local database.
 *
 * Full-replace strategy: fetch all suppliers → bulk upsert → delete stale records.
 * The GetSuppliers endpoint returns the complete supplier list (small dataset),
 * so reconciliation simply removes any local records not in the fetched set.
 */
final readonly class SyncSuppliersUseCase
{
    public function __construct(
        private InventoryClientInterface $inventoryClient,
        private SupplierRepositoryInterface $supplierRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * Synchronize all suppliers from Linnworks API to local database.
     *
     * @return SyncResult Results with fetched/saved/failed counts
     *
     * @throws AuthenticationExpiredException When Linnworks credentials invalid/expired
     * @throws ExternalServiceUnavailableException When Linnworks API unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response parsing fails
     * @throws ResourceNotFoundException When requested resource not found (404)
     * @throws DatabaseOperationFailedException On database operation failure
     * @throws DuplicateRecordException On constraint violation
     */
    public function execute(): SyncResult
    {
        $this->logger->info('Starting supplier directory sync from Linnworks');

        $suppliers = $this->inventoryClient->getSuppliers();
        $fetched = \count($suppliers);

        if ($fetched === 0) {
            $this->logger->info('Supplier directory sync completed: no suppliers found in Linnworks');

            return SyncResult::empty();
        }

        $saveResult = $this->supplierRepository->saveSuppliersBulk($suppliers);

        if ($saveResult->hasFailures()) {
            $this->logger->error('Failed to save some suppliers to database', [
                'failed_count' => $saveResult->failed,
                'failed_ids' => $saveResult->failedReferences,
            ]);
        }

        $pkSupplierIds = \array_map(
            static fn(Supplier $supplier): string => $supplier->pkSupplierId,
            $suppliers,
        );

        $deleted = $this->supplierRepository->deleteWhereNotIn($pkSupplierIds);

        if ($deleted > 0) {
            $this->logger->info('Reconciled stale suppliers', [
                'deleted' => $deleted,
            ]);
        }

        $this->logger->info('Supplier directory sync completed', [
            'fetched' => $fetched,
            'saved' => $saveResult->succeeded,
            'failed' => $saveResult->failed,
            'deleted' => $deleted,
        ]);

        return new SyncResult(
            fetched: $fetched,
            saved: $saveResult->succeeded,
            failed: $saveResult->failed,
            failedReferences: $saveResult->failedReferences,
        );
    }
}
