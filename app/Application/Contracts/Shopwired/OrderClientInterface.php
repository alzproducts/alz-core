<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiResponseException;

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
     * @param int $from Unix timestamp - start of range
     * @param int $to Unix timestamp - end of range
     *
     * @return list<Order> Orders with products=null, customFields=null
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function listOrdersInRange(int $from, int $to): array;

    /**
     * List orders within a date range - DETAIL mode.
     *
     * Fetches all pages automatically. Returns complete orders
     * with products and customFields populated.
     *
     * Use for syncs requiring complete order data (e.g., Mixpanel daily sync).
     * Heavier payload but avoids N+1 getOrderById calls.
     *
     * @param int $from Unix timestamp - start of range
     * @param int $to Unix timestamp - end of range
     *
     * @return list<Order> Orders with ALL fields populated
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function listOrdersInRangeWithDetails(int $from, int $to): array;

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
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function searchOrders(string $keyword): array;

    /**
     * Get a single order by ID - DETAIL mode.
     *
     * Returns complete order with ALL fields populated.
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function getOrderById(int $id): Order;

    /**
     * Get total order count.
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function getOrderCount(): int;

    /**
     * Get order count filtered by status ID.
     *
     * Status IDs are discoverable from OrderStatus.id in order responses.
     *
     * @param int $statusId Status ID from ShopWired (e.g., 1 for "Paid")
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function getOrderCountByStatus(int $statusId): int;
}
