<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use App\Application\Contracts\RepositoryWriteInterface;
use App\Application\Results\SaveManyResult;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Inventory\ValueObjects\Supplier;

/**
 * Repository for Linnworks supplier directory persistence.
 *
 * @extends RepositoryWriteInterface<Supplier>
 */
interface SupplierRepositoryInterface extends RepositoryWriteInterface
{
    /**
     * Bulk upsert suppliers using high-performance batch operations.
     *
     * @param list<Supplier> $suppliers Suppliers to persist
     *
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function saveSuppliersBulk(array $suppliers): SaveManyResult;

    /**
     * Delete suppliers whose pk_supplier_id is NOT in the provided list.
     *
     * Used for reconciliation: after upserting all suppliers from the API,
     * delete any local records that no longer exist in Linnworks.
     *
     * @param list<string> $pkSupplierIds IDs to keep
     *
     * @return int Number of deleted records
     *
     * @throws DatabaseOperationFailedException On deletion failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function deleteWhereNotIn(array $pkSupplierIds): int;
}
