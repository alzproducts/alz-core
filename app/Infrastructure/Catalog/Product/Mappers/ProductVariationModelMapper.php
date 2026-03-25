<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Product\Mappers;

use App\Domain\Catalog\Product\ValueObjects\Gtin;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationOption;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Catalog\Product\Factories\ProductCostPriceFactory;
use App\Infrastructure\Catalog\Product\Models\ProductVariationModel;

/**
 * Single source of truth for ProductVariationModel ↔ Domain mapping.
 *
 * Three mapping paths (mirrors ProductModelMapper):
 * - `toDomain()`: Basic conversion (internal/write paths)
 * - `toReadDomain()`: Enriched with Linnworks cost prices (show API)
 * - `toModelAttributes()`: Domain → Eloquent attributes (persistence)
 */
final class ProductVariationModelMapper
{
    public function __construct(
        private readonly ProductCostPriceFactory $costPriceFactory,
    ) {}

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
     * Read-path mapping: optionally enriches with Linnworks cost price (lazy-loaded).
     *
     * Falls back to ShopWired cost_price when no Linnworks price exists for the SKU.
     *
     * @param bool $enrichCostPrice Whether to look up Linnworks cost prices (avoid loading when not requested)
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function toReadDomain(ProductVariationModel $model, bool $enrichCostPrice = true): ProductVariation
    {
        $costPrice = ($enrichCostPrice && $model->sku !== null)
            ? $this->costPriceFactory->getCostPrice($model->sku) ?? $model->cost_price
            : $model->cost_price;

        return new ProductVariation(
            id: $model->external_id,
            productExternalId: $model->product_external_id,
            sku: $model->sku,
            price: $model->price,
            costPrice: $costPrice,
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
