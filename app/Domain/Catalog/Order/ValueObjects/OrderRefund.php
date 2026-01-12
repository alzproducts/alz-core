<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Order\ValueObjects;

use DateTimeImmutable;
use Webmozart\Assert\Assert;

/**
 * Order refund value object.
 *
 * Represents a refund applied to an order.
 */
final readonly class OrderRefund
{
    /**
     * @param int $externalId ShopWired refund ID
     * @param string $name Refund description/reason
     * @param float $value Refund amount
     * @param DateTimeImmutable $createdAt When refund was created in ShopWired
     */
    public function __construct(
        public int $externalId,
        public string $name,
        public float $value,
        public DateTimeImmutable $createdAt,
    ) {
        Assert::greaterThanEq($value, 0, 'Refund value cannot be negative');
    }
}
