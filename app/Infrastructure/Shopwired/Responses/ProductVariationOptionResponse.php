<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\Product\ValueObjects\ProductVariationOption;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Product Variation Option.
 *
 * Represents a single option attribute on a variation (e.g., "Color: Red").
 * Nested within ProductVariationResponse.
 *
 * @see https://shopwired.readme.io/reference/getproduct
 */
#[MapInputName(SnakeCaseMapper::class)]
final class ProductVariationOptionResponse extends Data implements DomainConvertibleInterface
{
    public function __construct(
        public readonly int $optionId,
        public readonly string $optionName,
        public readonly int $valueId,
        public readonly string $valueName,
    ) {}

    public function toDomain(): ProductVariationOption
    {
        return new ProductVariationOption(
            optionId: $this->optionId,
            optionName: $this->optionName,
            valueId: $this->valueId,
            valueName: $this->valueName,
        );
    }
}
