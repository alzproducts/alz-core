<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources;

use App\Application\Catalog\UseCases\GetProductResult;
use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Catalog\Filters\ValueObjects\ProductFilter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API resource for single product detail with conditional embeds.
 *
 * Wraps GetProductResult to access both the product and the includes list.
 * Base fields match ProductResource; embeds are added conditionally.
 *
 * @mixin GetProductResult
 */
final class ProductDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var GetProductResult $result */
        $result = $this->resource;
        $product = $result->product;

        $data = ProductResource::baseFields($product);

        if ($result->hasInclude('variations') && $product->variations !== null) {
            $data['variations'] = ProductVariationResource::collection($product->variations);
        }

        if ($result->hasInclude('description')) {
            $data['description'] = $product->description;
        }

        if ($result->hasInclude('cost_price')) {
            $data['cost_price'] = $product->costPrice;
        }

        if ($result->hasInclude('category_ids')) {
            $data['category_ids'] = $product->categoryIds;
        }

        if ($result->hasInclude('custom_fields')) {
            $data['custom_fields'] = \array_map(
                static fn(AbstractCustomFieldValue $field): array => $field->toArray(),
                $product->customFields,
            );
        }

        if ($result->hasInclude('filters')) {
            $data['filters'] = \array_map(
                static fn(ProductFilter $filter): array => $filter->toArray(),
                $product->filters,
            );
        }

        return $data;
    }
}
