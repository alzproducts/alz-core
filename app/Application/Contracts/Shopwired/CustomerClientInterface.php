<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Customer\ValueObjects\Customer;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use Generator;

/**
 * ShopWired Customers API client.
 *
 * ShopWired separates customers into trade (B2B, trade=1) and non-trade (B2C)
 * categories and cannot return both in a single request. iterateCustomerBatches()
 * hides that split, yielding both types sequentially in memory-efficient pages.
 *
 * @see https://shopwired.readme.io/reference/listcustomers
 */
interface CustomerClientInterface
{
    /**
     * Iterate customers in batches (memory-efficient).
     *
     * Yields trade customers first (newest first), then non-trade customers (newest first).
     * Both can be limited by page count, or null to fetch all.
     *
     * This method internally makes two separate API pagination passes since the
     * ShopWired API cannot return both customer types in a single request.
     *
     * Use cases:
     * - Full sync (null, null): Daily job syncing all ~68k customers (~45 min)
     * - Quick sync (5, 5): Hourly job catching recent signups (~1000 customers, ~2 min)
     * - Micro sync (1, 1): 5-min job catching very recent signups (~200 customers, ~30s)
     *
     * Page numbers are sequential across both passes (trade pages 1-N, non-trade N+1-M).
     *
     * @param int|null $maxTradePages Max trade pages (null = all ~5 pages, 1 page ≈ 100 customers)
     * @param int|null $maxNonTradePages Max non-trade pages (null = all ~677 pages, 1 page ≈ 100 customers)
     *
     * @return Generator<int, list<Customer>, mixed, void> Yields batches of customers (page number as key)
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function iterateCustomerBatches(
        ?int $maxTradePages = null,
        ?int $maxNonTradePages = null,
    ): Generator;

    /**
     * Get a single customer by ID.
     *
     * Works for both trade and non-trade customers — the ID is globally unique.
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When customer not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function getCustomerById(int $id): Customer;

    /**
     * Search for a customer by exact email match.
     *
     * Searches across BOTH trade and non-trade customers (no trade filter applied).
     * Returns null if no customer found with that email.
     * Assumes email uniqueness (returns first match).
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function searchByEmail(string $email): ?Customer;
}
