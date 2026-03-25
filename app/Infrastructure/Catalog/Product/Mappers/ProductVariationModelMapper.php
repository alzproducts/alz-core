<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Product\Mappers;

use App\Domain\Catalog\Product\ValueObjects\Gtin;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Catalog\Product\Factories\ProductCostPriceFactory;
use App\Infrastructure\Catalog\Product\Models\ProductVariationModel;

/**
 * Dedicated read-path mapper for product variations.
 *
 * Enriches variations with Linnworks cost prices via ProductCostPriceFactory.
 * The existing ProductVariationModel::toDomain() stays untouched for write/internal paths.
 *
 * This mapper is the first step toward a full Write Model / Read Model split.
 */
final class ProductVariationModelMapper
{
    public function __construct(
        private readonly ProductCostPriceFactory $costPriceFactory,
    ) {}

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
            options: ProductVariationModel::buildOptions($model->options),
        );
    }
}
