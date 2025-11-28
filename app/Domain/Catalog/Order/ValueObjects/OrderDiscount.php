<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Order\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Order discount value object.
 *
 * Represents a discount applied to an order.
 * IDs included for Mixpanel tracking.
 */
final readonly class OrderDiscount
{
    public function __construct(
        public string $name,
        public float $value,
        public ?string $type,
        public ?string $code,
        public ?int $voucherId,
        public ?int $offerId,
    ) {
        Assert::greaterThanEq($value, 0, 'Discount value cannot be negative');
    }
}
