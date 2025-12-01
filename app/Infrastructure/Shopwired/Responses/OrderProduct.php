<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Order Product.
 *
 * Detail-only: Only returned when products field is requested.
 * Snapshot of product data at time of purchase (not catalog product).
 *
 * @see https://shopwired.readme.io/reference/listorders
 */
#[MapInputName(SnakeCaseMapper::class)]
final class OrderProduct extends Data
{
    /**
     * @param list<array{name: string, value: string}> $variation
     * @param list<array{name: string, value: string}> $customFields
     */
    public function __construct(
        // Identifiers
        public readonly int $id,

        // Product info
        public readonly string $title,
        public readonly string $sku,

        // Pricing
        public readonly float $price,
        public readonly float $priceVat,
        public readonly float $total,
        public readonly float $totalVat,
        public readonly float $originalPrice,
        public readonly float $costPrice,

        // Quantity & Tax
        public readonly int $quantity,
        public readonly float $vatRate,

        // Notes (nullable - may not have comments)
        public readonly string $comments,

        // Nested arrays (default to empty)
        public readonly array $variation = [],
        public readonly array $customFields = [],
    ) {}

    public function toDomain(): \App\Domain\Catalog\Order\ValueObjects\OrderProduct
    {
        return new \App\Domain\Catalog\Order\ValueObjects\OrderProduct(
            id: $this->id,
            title: $this->title,
            sku: $this->sku,
            price: $this->price,
            priceVat: $this->priceVat,
            total: $this->total,
            totalVat: $this->totalVat,
            originalPrice: $this->originalPrice,
            costPrice: $this->costPrice,
            quantity: $this->quantity,
            vatRate: $this->vatRate,
            comments: $this->comments,
            variation: $this->variation,
            customFields: $this->customFields,
        );
    }
}
