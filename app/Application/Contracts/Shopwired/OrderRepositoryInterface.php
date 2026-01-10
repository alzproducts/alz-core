<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Exceptions\DatabaseOperationFailedException;
use App\Domain\Exceptions\ResourceNotFoundException;

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
}
