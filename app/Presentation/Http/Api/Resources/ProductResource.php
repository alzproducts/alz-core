<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources;

use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductImage;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API resource for Product domain value object.
 *
 * Omits description (large HTML, not needed for list view),
 * custom fields, filters, and category IDs (internal use only).
 *
 * @mixin Product
 */
final class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->resource;

        $data = [
            'id' => $product->id,
            'sku' => $product->sku,
            'gtin' => $product->gtin?->value,
            'title' => $product->title,
            'slug' => $product->slug,
            'url' => $product->url,
            'price' => $product->price,
            'sale_price' => $product->salePrice,
            'compare_price' => $product->comparePrice,
            'stock' => $product->stock,
            'is_active' => $product->isActive,
            'vat_exclusive' => $product->vatExclusive,
            'vat_relief' => $product->vatRelief,
            'weight' => $product->weight,
            'meta_title' => $product->metaTitle,
            'meta_description' => $product->metaDescription,
            'sort_order' => $product->sortOrder,
            'images' => \array_map(
                static fn(ProductImage $img): array => $img->toArray(),
                $product->images,
            ),
            'created_at' => $product->createdAt->format(DateTimeInterface::ATOM),
            'updated_at' => $product->updatedAt->format(DateTimeInterface::ATOM),
        ];

        if ($product->variations !== null) {
            $data['variations'] = ProductVariationResource::collection($product->variations);
        }

        return $data;
    }
}
