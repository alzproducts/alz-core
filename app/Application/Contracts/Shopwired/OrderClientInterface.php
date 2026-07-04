<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use DateTimeImmutable;
use Generator;

/**
 * ShopWired Orders API client.
 *
 * Handles order retrieval operations from ShopWired API.
 * Implementation handles HTTP communication, authentication, and response parsing.
 */
interface OrderClientInterface
{
    /**
     * List orders within a date range with full detail.
     *
     * Fetches all pages automatically. Returns complete orders
     * with products and customFields populated.
     *
     * @param DateTimeImmutable $from Start of range (timezone preserved, converted to timestamp internally)
     * @param DateTimeImmutable $to End of range (timezone preserved, converted to timestamp internally)
     *
     * @return list<Order> Orders with ALL fields populated
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function listOrdersInRange(DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Get a single order by ID - DETAIL mode.
     *
     * Returns complete order with ALL fields populated.
     *
     * @param int $id ShopWired order ID (must be positive)
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When order not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function getOrderById(int $id): Order;

    /**
     * Iterate orders in batches (memory-efficient).
     *
     * Orders sorted by date descending (newest first).
     * Page count can be limited for quick sync, or null to fetch all.
     *
     * Use cases:
     * - Full sync (null): Daily job syncing all orders
     * - Quick sync (N pages): Hourly job catching recent orders
     * - Micro sync (1 page): 5-min job catching very recent orders
     *
     * @param int|null $maxPages Maximum pages to fetch (null = all)
     *
     * @return Generator<int, list<Order>, mixed, void> Page number as key, batch of orders as value
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function iterateOrderBatches(?int $maxPages = null): Generator;
}
