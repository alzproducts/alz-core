<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources;

use App\Application\Catalog\UseCases\GetProductResult;
use App\Domain\Catalog\Filters\ValueObjects\ProductFilter;
use App\Domain\Catalog\Product\Enums\ProductInclude;
use App\Domain\Catalog\Product\ValueObjects\ProductSupplier;
use App\Domain\ValueObjects\IntId;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

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
    #[Override]
    public function toArray(Request $request): array
    {
        /** @var GetProductResult $result */
        $result = $this->resource;

        return ProductResource::baseFields($result->product)
            + $this->conditionalIncludes($result, $request)
            + ['meta' => $result->product->meta->toArray()];
    }

    /**
     * @return array<string, mixed>
     */
    private function conditionalIncludes(GetProductResult $result, Request $request): array
    {
        return $this->scalarIncludes($result)
            + $this->linnworksIncludes($result)
            + $this->collectionIncludes($result, $request);
    }

    /**
     * @return array<string, mixed>
     */
    private function scalarIncludes(GetProductResult $result): array
    {
        $product = $result->product;
        $data = [];
        $data['variations'] = ProductVariationResource::collection($product->variations ?? []);
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
     * Linnworks-sourced includes: inventory enrichment and stock levels.
     *
     * @return array<string, mixed>
     */
    private function linnworksIncludes(GetProductResult $result): array
    {
        $product = $result->product;
        $data = [];
        if ($result->hasInclude(ProductInclude::Inventory) && $product->inventory !== null) {
            $data['inventory'] = $product->inventory->toArray();
        }
        if ($result->hasInclude(ProductInclude::Stock) && $product->stock !== null) {
            $data['stock'] = $product->stock->toArray();
        }
        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function collectionIncludes(GetProductResult $result, Request $request): array
    {
        $product = $result->product;
        $data = [];
        if ($result->hasInclude(ProductInclude::CategoryIds)) {
            $data['category_ids'] = \array_map(static fn(IntId $id): int => $id->value, $product->categoryIds);
        }
        if ($result->hasInclude(ProductInclude::CustomFields)) {
            $data['custom_fields'] = CustomFieldValueResource::collection($product->customFields->toList())->resolve($request);
        }
        if ($result->hasInclude(ProductInclude::Filters)) {
            $data['filters'] = \array_map(static fn(ProductFilter $filter): array => $filter->toArray(), $product->filters);
        }
        return $data;
    }
}
