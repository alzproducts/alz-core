<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources;

use App\Domain\Catalog\Product\ValueObjects\ProductVariationOption;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API resource for ProductVariationView domain value object.
 *
 * @mixin ProductVariationView
 */
final class ProductVariationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProductVariationView $variation */
        $variation = $this->resource;

        return [
            'id' => $variation->id->value,
            'sku' => $variation->sku?->value,
            'gtin' => $variation->gtin?->value,
            'price' => $variation->price->toGross(),
            'cost_price' => $variation->costPrice?->toNet(),
            'sale_price' => $variation->salePrice?->toGross(),
            'profit_margin' => $variation->profitMargin,
            'is_on_sale' => $variation->isOnSale,
            'stock' => $variation->stock,
            'weight' => $variation->weight?->value,
            'image_index' => $variation->imageIndex,
            'options' => \array_map(
                static fn(ProductVariationOption $opt): array => $opt->toArray(),
                $variation->options,
            ),
        ];
    }
}
