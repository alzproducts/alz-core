<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Catalog\Order\ValueObjects\OrderLifecycleStatus;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use DateTimeImmutable;
use Generator;

/**
 * ShopWired Orders API client.
 *
 * Handles order retrieval operations from ShopWired API.
 * Implementation handles HTTP communication, authentication, and response parsing.
 *
 * Two-mode approach:
 * - Standard: Lightweight orders without products/customFields (null values)
 * - Detail: Complete orders with products and customFields populated
 */
interface OrderClientInterface
{
    /**
     * List orders within a date range - STANDARD mode.
     *
     * Fetches all pages automatically. Returns lightweight orders
     * without products/customFields (those fields will be null).
     *
     * @param DateTimeImmutable $from Start of range (timezone preserved, converted to timestamp internally)
     * @param DateTimeImmutable $to End of range (timezone preserved, converted to timestamp internally)
     *
     * @return list<Order> Orders with products=null, customFields=null
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function listOrdersInRange(DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * List orders within a date range - DETAIL mode.
     *
     * Fetches all pages automatically. Returns complete orders
     * with products and customFields populated.
     *
     * Use for syncs requiring complete order data (e.g., Mixpanel daily sync).
     * Heavier payload but avoids N+1 getOrderById calls.
     *
     * @param DateTimeImmutable $from Start of range (timezone preserved, converted to timestamp internally)
     * @param DateTimeImmutable $to End of range (timezone preserved, converted to timestamp internally)
     *
     * @return list<Order> Orders with ALL fields populated
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function listOrdersInRangeWithDetails(DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Search orders by keyword - STANDARD mode.
     *
     * Searches by reference, customer name, email, etc.
     * Returns empty array when no orders match the keyword.
     *
     * @warning API search may not be exact match. Callers MUST verify
     * returned orders match expected criteria before use.
     *
     * @param string $keyword Search term (reference, name, email, etc.)
     *
     * @return list<Order> Matching orders (empty array if none found), products=null, customFields=null
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function searchOrders(string $keyword): array;

    /**
     * Get a single order by ID - DETAIL mode.
     *
     * Returns complete order with ALL fields populated.
     *
     * @param int $id ShopWired order ID (must be positive)
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When order not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function getOrderById(int $id): Order;

    /**
     * Get total order count.
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function getOrderCount(): int;

    /**
     * Get order count filtered by status ID.
     *
     * Status IDs are discoverable from OrderStatus.id in order responses.
     *
     * @param int $statusId Status ID from ShopWired (e.g., 1 for "Paid")
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function getOrderCountByStatus(int $statusId): int;

    /**
     * Update an order's lifecycle status.
     *
     * @param int $orderId Order ID to update
     * @param OrderLifecycleStatus $status New lifecycle status
     * @param bool $notifyCustomer Whether to send status update email to customer
     * @param string|null $trackingUrl New tracking URL value (null = don't update)
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When order not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     */
    public function updateOrderStatus(
        int $orderId,
        OrderLifecycleStatus $status,
        bool $notifyCustomer = false,
        ?string $trackingUrl = null,
    ): void;

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
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function iterateOrderBatches(?int $maxPages = null): Generator;
}
