<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources;

use App\Application\Catalog\UseCases\GetProductResult;
use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Catalog\Filters\ValueObjects\ProductFilter;
use App\Domain\Catalog\Product\Enums\ProductInclude;
use App\Domain\Catalog\Product\ValueObjects\ProductSupplier;
use App\Domain\ValueObjects\IntId;
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

        return ProductResource::baseFields($result->product)
            + $this->conditionalIncludes($result);
    }

    /**
     * @return array<string, mixed>
     */
    private function conditionalIncludes(GetProductResult $result): array
    {
        return $this->scalarIncludes($result) + $this->collectionIncludes($result);
    }

    /**
     * @return array<string, mixed>
     */
    private function scalarIncludes(GetProductResult $result): array
    {
        $product = $result->product;
        $data = [];
        if ($result->hasInclude(ProductInclude::Variations) && $product->variations !== null) {
            $data['variations'] = ProductVariationResource::collection($product->variations);
        }
        if ($result->hasInclude(ProductInclude::Description)) {
            $data['description'] = $product->description;
        }
        if ($result->hasInclude(ProductInclude::SaleSettings) && $product->saleSettings !== null) {
            $data['sale_settings'] = $product->saleSettings->toArray();
        }
        if ($result->hasInclude(ProductInclude::Suppliers) && $product->suppliers !== null) {
            $data['suppliers'] = \array_map(
                static fn(ProductSupplier $s): array => $s->toArray(),
                $product->suppliers,
            );
        }
        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function collectionIncludes(GetProductResult $result): array
    {
        $product = $result->product;
        $data = [];
        if ($result->hasInclude(ProductInclude::CategoryIds)) {
            $data['category_ids'] = \array_map(static fn(IntId $id): int => $id->value, $product->categoryIds);
        }
        if ($result->hasInclude(ProductInclude::CustomFields)) {
            $data['custom_fields'] = \array_map(static fn(AbstractCustomFieldValue $field): array => $field->toArray(), $product->customFields);
        }
        if ($result->hasInclude(ProductInclude::Filters)) {
            $data['filters'] = \array_map(static fn(ProductFilter $filter): array => $filter->toArray(), $product->filters);
        }
        return $data;
    }
}
