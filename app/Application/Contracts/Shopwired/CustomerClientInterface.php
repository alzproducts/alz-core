<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Customer\ValueObjects\Customer;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiResponseException;

/**
 * ShopWired Customers API client.
 *
 * Handles customer retrieval operations from ShopWired API.
 * Implementation handles HTTP communication, authentication, and response parsing.
 */
interface CustomerClientInterface
{
    /**
     * List ALL customers with embedded data (paginated fetch).
     *
     * Fetches all pages automatically. Use for complete customer sync/caching.
     *
     * @return list<Customer>
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function listAllCustomers(): array;

    /**
     * List ALL trade customers with embedded data (paginated fetch).
     *
     * Fetches all pages automatically. Trade customers only (trade=1).
     *
     * @return list<Customer>
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function listAllTradeCustomers(): array;

    /**
     * List customers (single page).
     *
     * @return list<Customer>
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function listCustomers(): array;

    /**
     * Get a single customer by ID.
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function getCustomerById(int $id): Customer;

    /**
     * Get the total count of customers.
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function getCustomerCount(): int;

    /**
     * Get the total count of trade customers only.
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function getTradeCustomerCount(): int;

    /**
     * Search for a customer by exact email match.
     *
     * Returns null if no customer found with that email.
     * Assumes email uniqueness (returns first match).
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function searchByEmail(string $email): ?Customer;
}
