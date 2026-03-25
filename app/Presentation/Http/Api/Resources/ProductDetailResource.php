<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources;

use App\Application\Catalog\UseCases\GetProductResult;
use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Catalog\Filters\ValueObjects\ProductFilter;
use DateTimeImmutable;
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
            $data['custom_fields'] = self::serializeCustomFields($product->customFields);
        }

        if ($result->hasInclude('filters')) {
            $data['filters'] = self::serializeFilters($product->filters);
        }

        return $data;
    }

    /**
     * Serialize custom fields to API-friendly format.
     *
     * @param list<AbstractCustomFieldValue> $customFields
     *
     * @return list<array{name: string, type: string, value: string|bool|array<mixed>|DateTimeImmutable}>
     */
    private static function serializeCustomFields(array $customFields): array
    {
        return \array_map(
            static fn(AbstractCustomFieldValue $field): array => [
                'name' => $field->name(),
                'type' => $field->type()->value,
                'value' => $field->rawValue(),
            ],
            $customFields,
        );
    }

    /**
     * Serialize filters to API-friendly format.
     *
     * @param list<ProductFilter> $filters
     *
     * @return list<array{title: string, values: list<string>}>
     */
    private static function serializeFilters(array $filters): array
    {
        return \array_map(
            static fn(ProductFilter $filter): array => [
                'title' => $filter->title(),
                'values' => $filter->values,
            ],
            $filters,
        );
    }
}
