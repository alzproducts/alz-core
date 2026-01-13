<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Order\ValueObjects;

use DateTimeImmutable;
use Webmozart\Assert\Assert;

/**
 * Order product value object.
 *
 * Snapshot of product data at time of purchase.
 * Different from catalog Product - contains order-specific pricing.
 *
 * @property array<int, array{name: string, value: string}> $customFields
 */
final readonly class OrderProduct
{
    /**
     * @param bool $isPreorder Whether this is a pre-order item (derived from comments containing "Preorder:")
     * @param DateTimeImmutable|null $preorderDate Expected availability date for pre-order items
     * @param array<int, ProductVariation> $variation
     * @param array<int, array{name: string, value: string}> $customFields
     */
    public function __construct(
        public int $id,
        public int $orderExternalId,
        public string $title,
        public string $sku,
        public float $price,
        public float $priceVat,
        public float $total,
        public float $totalVat,
        public float $originalPrice,
        public ?float $costPrice,
        public int $quantity,
        public float $vatRate,
        public string $comments,
        public bool $isPreorder,
        public ?DateTimeImmutable $preorderDate = null,
        public array $variation = [],
        public array $customFields = [],
    ) {
        Assert::greaterThan($id, 0, 'Product ID must be positive');
        Assert::greaterThan($orderExternalId, 0, 'Order external ID must be positive');
        Assert::greaterThan($quantity, 0, 'Quantity must be positive');
        Assert::greaterThanEq($vatRate, 0, 'VAT rate cannot be negative');
    }
}
