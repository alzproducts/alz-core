<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Application\Contracts\RepositoryInterface;
use App\Application\Results\SaveManyResult;
use App\Domain\Customer\ValueObjects\Customer;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;

/**
 * Repository for ShopWired customer persistence.
 *
 * @extends RepositoryInterface<Customer>
 */
interface CustomerRepositoryInterface extends RepositoryInterface
{
    /**
     * Get customer by email address.
     *
     * @throws ResourceNotFoundException When customer not found
     * @throws DatabaseOperationFailedException On query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function getByEmail(string $email): Customer;

    /**
     * Find customer by email address without exception.
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function findByEmail(string $email): ?Customer;

    /**
     * Check if customer exists by email without exception.
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function existsByEmail(string $email): bool;

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
}
