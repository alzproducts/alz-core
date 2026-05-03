<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Product\Mappers;

use App\Domain\Catalog\Product\ValueObjects\Gtin;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationOption;
use App\Infrastructure\Catalog\Product\Models\ProductVariationModel;

/**
 * Maps ProductVariationModel (write model) ↔ Domain ProductVariation.
 *
 * Two mapping paths:
 * - `toDomain()`: Eloquent → Domain (internal/write paths)
 * - `toModelAttributes()`: Domain → Eloquent attributes (persistence)
 *
 * Read-path mapping (view model → ProductVariationView) lives in ProductVariationViewModelMapper.
 */
final class ProductVariationModelMapper
{
    /**
     * Convert Eloquent model to Domain ProductVariation.
     *
     * Basic mapping without enrichment — used by internal/write paths.
     */
    public static function toDomain(ProductVariationModel $model): ProductVariation
    {
        return new ProductVariation(
            id: $model->external_id,
            productExternalId: $model->product_external_id,
            sku: $model->sku,
            price: $model->price,
            costPrice: $model->cost_price,
            salePrice: $model->sale_price,
            stock: $model->stock,
            weight: $model->weight,
            gtin: $model->gtin !== null ? Gtin::fromTrusted($model->gtin) : null,
            mpn: $model->mpn,
            imageIndex: $model->image_index,
            options: self::buildOptions($model->options),
        );
    }

    /**
     * Convert Domain ProductVariation to Eloquent model attributes.
     *
     * @return array<string, mixed>
     */
    public static function toModelAttributes(ProductVariation $entity): array
    {
        return [
            'product_external_id' => $entity->productExternalId,
            'external_id' => $entity->id,
            'sku' => $entity->sku,
            'price' => $entity->price,
            'cost_price' => $entity->costPrice,
            'sale_price' => $entity->salePrice,
            'stock' => $entity->stock,
            'weight' => $entity->weight,
            'gtin' => $entity->gtin?->value,
            'mpn' => $entity->mpn,
            'image_index' => $entity->imageIndex,
            'options' => \array_map(
                static fn(ProductVariationOption $opt): array => $opt->toArray(),
                $entity->options,
            ),
        ];
    }

    /**
     * Convert DB options arrays to ProductVariationOption objects.
     *
     * @param list<array{option_id: int, option_name: string, value_id: int, value_name: string}> $options
     *
     * @return list<ProductVariationOption>
     */
    private static function buildOptions(array $options): array
    {
        return \array_map(
            static fn(array $opt): ProductVariationOption => ProductVariationOption::fromArray($opt),
            $options,
        );
    }
}
