<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\Order\ValueObjects\OrderProduct;
use App\Domain\Catalog\Order\ValueObjects\ProductVariation;
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
final class OrderProductResponse extends Data
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

        // Pricing (required)
        public readonly float $price,
        public readonly float $priceVat,
        public readonly float $total,
        public readonly float $totalVat,
        public readonly float $originalPrice,

        // Quantity & Tax
        public readonly int $quantity,
        public readonly float $vatRate,

        // Notes
        public readonly string $comments,

        // Optional fields (must come last in PHP 8+)
        public readonly ?float $costPrice = null,  // Nullable: older orders may not have cost data
        public readonly array $variation = [],
        public readonly array $customFields = [],
    ) {}

    public function toDomain(int $orderExternalId): OrderProduct
    {
        return new OrderProduct(
            id: $this->id,
            orderExternalId: $orderExternalId,
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
            variation: \array_map(ProductVariation::fromArray(...), $this->variation),
            customFields: $this->customFields,
        );
    }
}
