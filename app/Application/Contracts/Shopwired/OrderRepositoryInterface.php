<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Application\Contracts\RepositoryWriteInterface;
use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use DateTimeImmutable;

/**
 * Repository for ShopWired order persistence.
 *
 * @extends RepositoryWriteInterface<Order>
 */
interface OrderRepositoryInterface extends RepositoryWriteInterface
{
    /**
     * Get order by customer-facing reference number.
     *
     * When multiple orders share the same reference (e.g., edited orders in ShopWired
     * where the original is cancelled and a new order is created with the same
     * customer-facing reference), returns the "active" order:
     * 1. Non-cancelled order takes priority (if exactly one exists)
     * 2. Highest external_id wins as tiebreaker (most recent ShopWired order)
     *
     * @throws ResourceNotFoundException When no order found with this reference
     * @throws DatabaseOperationFailedException On query failure
     */
    public function getByReference(int $reference): Order;

    /**
     * Get orders placed within a date range (inclusive).
     *
     * Orders are filtered by `order_placed_at` timestamp.
     * Results include all relations (products, discounts, refunds, comments).
     *
     * Note: This method applies business filters (test email exclusion, duplicate
     * deduplication). For raw unfiltered data, use getAllOrdersInDateRange().
     *
     * @return list<Order>
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function getOrdersInDateRange(DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Get ALL orders placed within a date range (no filtering).
     *
     * Returns raw database contents without any business filtering:
     * - Includes test customer orders
     * - Includes duplicate references (cancelled + active versions)
     *
     * Use this for auditing/debugging. For business operations, use getOrdersInDateRange().
     *
     * @return list<Order>
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function getAllOrdersInDateRange(DateTimeImmutable $from, DateTimeImmutable $to): array;
}
