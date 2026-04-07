<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources;

use App\Domain\Catalog\Product\ValueObjects\ProductImage;
use App\Domain\Catalog\Product\ValueObjects\ProductView;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API resource for ProductView domain value object.
 *
 * Omits description (large HTML, not needed for list view),
 * custom fields, filters, and category IDs (internal use only).
 *
 * @mixin ProductView
 */
final class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProductView $product */
        $product = $this->resource;

        $data = self::baseFields($product);

        if ($product->variations !== null) {
            $data['variations'] = ProductVariationResource::collection($product->variations);
        }

        if ($product->inventory !== null) {
            $data['inventory'] = $product->inventory->toArray();
        }

        if ($product->stock !== null) {
            $data['stock'] = $product->stock->toArray();
        }

        return $data;
    }

    /**
     * Base scalar fields shared by list and detail resources.
     *
     * @return array<string, mixed>
     */
    public static function baseFields(ProductView $product): array
    {
        return [
            'id' => $product->id->value,
            'sku' => $product->sku?->value,
            'gtin' => $product->gtin?->value,
            'title' => $product->title,
            'slug' => $product->slug,
            'url' => $product->url,
            'price' => $product->price->toGross(),
            'cost_price' => $product->costPrice?->toNet(),
            'sale_price' => $product->salePrice?->toGross(),
            'rrp' => $product->rrp?->toGross(),
            'effective_price' => $product->effectivePrice->toGross(),
            'profit_margin' => $product->profitMargin,
            'is_active' => $product->isActive,
            'is_on_sale' => $product->isOnSale,
            'has_any_sale' => $product->hasAnySale,
            'has_free_delivery' => $product->hasFreeDelivery,
            'vat_exclusive' => $product->vatExclusive,
            'vat_relief' => $product->vatRelief,
            'meta_title' => $product->metaTitle,
            'meta_description' => $product->metaDescription,
            'default_supplier' => $product->defaultSupplier?->toArray(),
            'free_delivery' => $product->freeDelivery?->value,
            'sort_order' => $product->sortOrder,
            'images' => \array_map(
                static fn(ProductImage $img): array => $img->toArray(),
                $product->images,
            ),
            'created_at' => $product->createdAt->format(DateTimeInterface::ATOM),
            'updated_at' => $product->updatedAt->format(DateTimeInterface::ATOM),
        ];
    }
}
