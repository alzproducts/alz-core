<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Customer\ValueObjects\Customer;
use App\Domain\Exceptions\DatabaseOperationFailedException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\ResourceNotFoundException;

/**
 * Repository for ShopWired customer persistence.
 *
 * Extends base ShopWired repository with customer-specific query methods.
 *
 * @extends ShopwiredRepositoryInterface<Customer>
 */
interface CustomerRepositoryInterface extends ShopwiredRepositoryInterface
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
}
