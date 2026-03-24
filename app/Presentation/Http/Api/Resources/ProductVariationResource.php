<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources;

use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API resource for ProductVariation domain value object.
 *
 * @mixin ProductVariation
 */
final class ProductVariationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProductVariation $variation */
        $variation = $this->resource;

        return [
            'id' => $variation->id,
            'sku' => $variation->sku,
            'gtin' => $variation->gtin?->value,
            'price' => $variation->price,
            'sale_price' => $variation->salePrice,
            'stock' => $variation->stock,
            'weight' => $variation->weight,
            'image_index' => $variation->imageIndex,
            'options' => \array_map(
                static fn(ProductVariationOption $opt): array => $opt->toArray(),
                $variation->options,
            ),
        ];
    }
}
