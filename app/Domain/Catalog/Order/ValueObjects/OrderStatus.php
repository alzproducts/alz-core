<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Order\ValueObjects;

/**
 * Order status value object.
 *
 * Wraps the status type enum with the raw API type string.
 * Includes ShopWired's status ID and sort order for persistence.
 */
final readonly class OrderStatus
{
    public function __construct(
        public int $id,
        public OrderStatusType $name,
        public string $type,
        public int $sortOrder,
    ) {}
}
