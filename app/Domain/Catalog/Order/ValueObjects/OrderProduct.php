<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Order\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Order product value object.
 *
 * Snapshot of product data at time of purchase.
 * Different from catalog Product - contains order-specific pricing.
 *
 * @property array<int, array{name: string, value: string}> $variation
 * @property array<int, array{name: string, value: string}> $customFields
 */
final readonly class OrderProduct
{
    /**
     * @param array<int, array{name: string, value: string}> $variation
     * @param array<int, array{name: string, value: string}> $customFields
     */
    public function __construct(
        public int $id,
        public string $title,
        public string $sku,
        public float $price,
        public float $priceVat,
        public float $total,
        public float $totalVat,
        public float $originalPrice,
        public float $costPrice,
        public int $quantity,
        public float $vatRate,
        public ?string $comments,
        public array $variation = [],
        public array $customFields = [],
    ) {
        Assert::greaterThan($id, 0, 'Product ID must be positive');
        Assert::greaterThan($quantity, 0, 'Quantity must be positive');
        Assert::greaterThanEq($vatRate, 0, 'VAT rate cannot be negative');
    }
}
