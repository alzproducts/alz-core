<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Order\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Order refund value object.
 *
 * Represents a refund applied to an order. Contains only business-essential
 * fields - infrastructure details (external_id, timestamps) stay in the
 * Infrastructure layer.
 */
final readonly class OrderRefund
{
    public function __construct(
        public string $name,
        public float $value,
    ) {
        Assert::greaterThanEq($value, 0, 'Refund value cannot be negative');
    }
}
