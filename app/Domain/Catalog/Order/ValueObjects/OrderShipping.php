<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Order\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Order shipping method value object.
 *
 * Represents the shipping method applied to an order.
 * ID excluded - use name for business logic.
 */
final readonly class OrderShipping
{
    public function __construct(
        public string $name,
        public float $value,
        public float $vatRate,
    ) {
        Assert::greaterThanEq($value, 0, 'Shipping value cannot be negative');
        Assert::greaterThanEq($vatRate, 0, 'VAT rate cannot be negative');
    }
}
