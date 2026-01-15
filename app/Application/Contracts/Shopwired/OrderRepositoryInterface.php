<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Exceptions\DatabaseOperationFailedException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\ResourceNotFoundException;
use DateTimeImmutable;

/**
 * Repository for ShopWired order persistence.
 *
 * Extends base ShopWired repository with order-specific query methods.
 *
 * @extends ShopwiredRepositoryInterface<Order>
 */
interface OrderRepositoryInterface extends ShopwiredRepositoryInterface
{
    /**
     * Get order by customer-facing reference number.
     *
     * @throws ResourceNotFoundException When order not found
     * @throws DatabaseOperationFailedException On query failure
     */
    public function getByReference(int $reference): Order;

    /**
     * Get orders placed within a date range (inclusive).
     *
     * Orders are filtered by `order_placed_at` timestamp.
     * Results include all relations (products, discounts, refunds, comments).
     *
     * @return list<Order>
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function getOrdersInDateRange(DateTimeImmutable $from, DateTimeImmutable $to): array;
}
