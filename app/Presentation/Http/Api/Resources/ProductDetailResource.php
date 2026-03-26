<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources;

use App\Application\Catalog\UseCases\GetProductResult;
use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Catalog\Filters\ValueObjects\ProductFilter;
use App\Domain\ValueObjects\IntId;
use App\Presentation\Http\Api\Enums\ProductIncludeEnum;
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

        if ($result->hasInclude(ProductIncludeEnum::Variations->value) && $product->variations !== null) {
            $data['variations'] = ProductVariationResource::collection($product->variations);
        }

        if ($result->hasInclude(ProductIncludeEnum::Description->value)) {
            $data['description'] = $product->description;
        }

        if ($result->hasInclude(ProductIncludeEnum::CategoryIds->value)) {
            $data['category_ids'] = \array_map(
                static fn(IntId $id): int => $id->value,
                $product->categoryIds,
            );
        }

        if ($result->hasInclude(ProductIncludeEnum::CustomFields->value)) {
            $data['custom_fields'] = \array_map(
                static fn(AbstractCustomFieldValue $field): array => $field->toArray(),
                $product->customFields,
            );
        }

        if ($result->hasInclude(ProductIncludeEnum::Filters->value)) {
            $data['filters'] = \array_map(
                static fn(ProductFilter $filter): array => $filter->toArray(),
                $product->filters,
            );
        }

        if ($result->hasInclude(ProductIncludeEnum::SaleSettings->value) && $product->saleSettings !== null) {
            $data['sale_settings'] = $product->saleSettings->toArray();
        }

        return $data;
    }
}
