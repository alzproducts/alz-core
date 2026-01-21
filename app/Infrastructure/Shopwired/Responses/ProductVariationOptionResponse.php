<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\Product\ValueObjects\ProductVariationOption;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use Spatie\LaravelData\Data;

/**
 * ShopWired API Response: Product Variation Option (Value).
 *
 * Represents a single option attribute on a variation (e.g., "Color: Red").
 * Nested within ProductVariationResponse under `values` key.
 *
 * API structure:
 * ```
 * {
 *   "id": 19160148,        // value ID
 *   "name": "Standard Fixed", // value name
 *   "option": {
 *     "id": 3702351,       // option ID
 *     "name": "Type"       // option name
 *   }
 * }
 * ```
 *
 * @see https://shopwired.readme.io/reference/getproduct
 */
final class ProductVariationOptionResponse extends Data implements DomainConvertibleInterface
{
    /**
     * @param array{id: int, name: string} $option Nested option definition
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly array $option,
    ) {}

    public function toDomain(): ProductVariationOption
    {
        return new ProductVariationOption(
            optionId: $this->option['id'],
            optionName: $this->option['name'],
            valueId: $this->id,
            valueName: $this->name,
        );
    }
}
