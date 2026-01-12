<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Order\ValueObjects;

use DateTimeImmutable;
use Webmozart\Assert\Assert;

/**
 * Order refund value object.
 *
 * Represents a refund applied to an order.
 *
 * Note: external_id stays in Infrastructure layer (debugging only, not business-essential).
 */
final readonly class OrderRefund
{
    /**
     * @param string $name Refund description/reason
     * @param float $value Refund amount
     * @param DateTimeImmutable|null $createdAt When refund was created in ShopWired
     */
    public function __construct(
        public string $name,
        public float $value,
        public ?DateTimeImmutable $createdAt = null,
    ) {
        Assert::greaterThanEq($value, 0, 'Refund value cannot be negative');
    }
}
