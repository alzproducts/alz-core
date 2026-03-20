<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\Product\ValueObjects\PriceUpdateItemResult;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use Spatie\LaravelData\Data;

/**
 * Response DTO for a single item from POST products/prices.
 *
 * Each item in the batch response indicates whether the price was updated,
 * and if so, the ShopWired product ID and whether the SKU is a variation.
 */
final class PriceUpdateItemResponse extends Data implements DomainConvertibleInterface
{
    public function __construct(
        public readonly string $sku,
        public readonly bool $updated,
        public readonly ?int $productId = null,
        public readonly ?bool $variation = null,
    ) {}

    public function toDomain(): PriceUpdateItemResult
    {
        return new PriceUpdateItemResult(
            sku: Sku::fromTrusted($this->sku),
            updated: $this->updated,
            productId: $this->productId !== null ? IntId::from($this->productId) : null,
            isVariation: $this->variation,
        );
    }
}
