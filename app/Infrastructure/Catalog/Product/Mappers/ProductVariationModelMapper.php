<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Product\Mappers;

use App\Domain\Catalog\Product\ValueObjects\Gtin;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationOption;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationView;
use App\Infrastructure\Catalog\Product\Models\ProductVariationModel;
use App\Infrastructure\Catalog\Product\Models\ProductVariationViewModel;

/**
 * Single source of truth for ProductVariationModel ↔ Domain mapping.
 *
 * Three mapping paths (mirrors ProductModelMapper):
 * - `toDomain()`: Basic conversion (internal/write paths)
 * - `toViewDomain()`: API projection from view model with resolved prices and Linnworks cost
 * - `toModelAttributes()`: Domain → Eloquent attributes (persistence)
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
     * API projection: returns a domain-typed ProductVariationView from a view model.
     *
     * Passes primitives directly — the VO self-constructs domain types.
     * Prices are already resolved (parent inheritance applied in SQL via COALESCE).
     *
     * @param bool $vatExclusive Whether prices exclude VAT (from parent product)
     */
    public function toViewDomain(
        ProductVariationViewModel $model,
        bool $vatExclusive,
    ): ProductVariationView {
        return new ProductVariationView(
            externalId: $model->external_id,
            sku: $model->sku,
            gtin: $model->gtin,
            price: $model->price,
            costPrice: $model->cost_price,
            salePrice: $model->sale_price,
            effectivePrice: $model->effective_price,
            isOnSale: $model->is_on_sale,
            profitMargin: $model->profit_margin,
            stock: $model->stock,
            weight: $model->weight,
            vatExclusive: $vatExclusive,
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
