<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Application\Contracts\RepositoryWriteInterface;
use App\Application\Results\SaveManyResult;
use App\Domain\Customer\ValueObjects\Customer;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;

/**
 * Repository for ShopWired customer persistence.
 *
 * @extends RepositoryWriteInterface<Customer>
 */
interface CustomerRepositoryInterface extends RepositoryWriteInterface
{
    /**
     * Get trade status for multiple customers by their ShopWired IDs.
     *
     * Returns only customers that exist in the database. Missing IDs are
     * silently omitted from the result (caller should check for completeness).
     *
     * @param list<int> $customerIds ShopWired customer IDs
     *
     * @return array<int, bool> Map of customer ID → is_trade status
     *
     * @throws DatabaseOperationFailedException On query failure
     */
    public function getTradeStatusByIds(array $customerIds): array;

    /**
     * Bulk upsert customers using high-performance batch operations.
     *
     * Use this for large batches of customers (e.g., sync operations).
     * Automatically batches into chunks and falls back to per-row on errors.
     *
     * @param list<Customer> $customers Customers to persist
     * @param int $batchSize Rows per batch (default: 500)
     *
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function saveCustomersBulk(array $customers, int $batchSize = 500): SaveManyResult;

    /**
     * Delete a customer by their ShopWired external ID.
     *
     * Used by `customer.deleted` webhook.
     *
     * @throws ResourceNotFoundException When no customer found with this external ID
     * @throws DatabaseOperationFailedException On deletion failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function deleteByExternalId(IntId $externalId): void;
}
