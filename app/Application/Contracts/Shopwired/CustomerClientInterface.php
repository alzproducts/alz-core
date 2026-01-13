<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Customer\ValueObjects\Customer;
use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiRequestException;
use App\Domain\Exceptions\InvalidApiResponseException;
use App\Domain\Exceptions\ResourceNotFoundException;
use Generator;

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
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function listAllCustomers(): array;

    /**
     * List ALL trade customers with embedded data (paginated fetch).
     *
     * Fetches all pages automatically. Trade customers only (trade=1).
     *
     * @return list<Customer>
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function listAllTradeCustomers(): array;

    /**
     * Iterate ALL customers in batches (memory-efficient).
     *
     * Unlike listAllCustomers() which loads all customers into memory at once,
     * this generator yields batches of ~100 customers per page, allowing the
     * caller to process and discard each batch before fetching the next.
     *
     * Ideal for syncing large customer datasets (~60k) where memory is constrained.
     * Caller can buffer multiple pages before saving (e.g., 10 pages = ~1000 customers).
     *
     * @return Generator<int, list<Customer>, mixed, void> Yields batches of customers (page number as key)
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function iterateAllCustomerBatches(): Generator;

    /**
     * List customers (single page).
     *
     * @return list<Customer>
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function listCustomers(): array;

    /**
     * Get a single customer by ID.
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When customer not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function getCustomerById(int $id): Customer;

    /**
     * Get the total count of customers.
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function getCustomerCount(): int;

    /**
     * Get the total count of trade customers only.
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function getTradeCustomerCount(): int;

    /**
     * Search for a customer by exact email match.
     *
     * Returns null if no customer found with that email.
     * Assumes email uniqueness (returns first match).
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function searchByEmail(string $email): ?Customer;
}
