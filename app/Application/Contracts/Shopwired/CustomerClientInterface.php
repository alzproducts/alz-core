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
 * IMPORTANT: ShopWired separates customers into two distinct categories:
 * - Non-trade (regular B2C customers) — returned when trade filter is omitted or trade=0
 * - Trade (B2B customers with trade accounts) — returned only when trade=1
 *
 * The API does NOT support fetching all customers in a single request. When no trade
 * filter is specified, only non-trade customers are returned (this is the API default).
 *
 * Methods are explicitly named (NonTrade/Trade) to reflect which customer type they fetch.
 * To work with ALL customers, call both non-trade and trade methods separately.
 */
interface CustomerClientInterface
{
    /**
     * List all NON-TRADE customers with embedded data (paginated fetch).
     *
     * Fetches all pages automatically. Non-trade customers only (trade=0/omitted).
     * Use for complete non-trade customer sync/caching.
     *
     * @return list<Customer>
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function listAllNonTradeCustomers(): array;

    /**
     * List all TRADE customers with embedded data (paginated fetch).
     *
     * Fetches all pages automatically. Trade customers only (trade=1).
     * Use for complete trade customer sync/caching.
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
     * Iterate NON-TRADE customers in batches (memory-efficient).
     *
     * Unlike listAllNonTradeCustomers() which loads all customers into memory at once,
     * this generator yields batches of ~100 customers per page, allowing the caller
     * to process and discard each batch before fetching the next.
     *
     * Ideal for syncing large customer datasets (~60k) where memory is constrained.
     * Caller can buffer multiple pages before saving (e.g., 10 pages = ~1000 customers).
     *
     * NOTE: Only yields non-trade customers (trade=0). For trade customers, use
     * listAllTradeCustomers() or implement a trade batch iterator if needed.
     *
     * @return Generator<int, list<Customer>, mixed, void> Yields batches of customers (page number as key)
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function iterateNonTradeCustomerBatches(): Generator;

    /**
     * List non-trade customers (single page, default parameters).
     *
     * Returns first page of non-trade customers without embeds or custom fields.
     * For full data or pagination control, use listAllNonTradeCustomers() or
     * iterateNonTradeCustomerBatches().
     *
     * @return list<Customer>
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function listNonTradeCustomers(): array;

    /**
     * Get a single customer by ID.
     *
     * Works for both trade and non-trade customers — the ID is globally unique.
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When customer not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function getCustomerById(int $id): Customer;

    /**
     * Get the total count of NON-TRADE customers.
     *
     * Returns count of customers where trade=0 (or trade filter omitted).
     * For trade customer count, use getTradeCustomerCount().
     * For total count of all customers, sum both methods.
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function getNonTradeCustomerCount(): int;

    /**
     * Get the total count of TRADE customers only.
     *
     * Returns count of customers where trade=1.
     * For non-trade customer count, use getNonTradeCustomerCount().
     * For total count of all customers, sum both methods.
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
     * Searches across BOTH trade and non-trade customers (no trade filter applied).
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
