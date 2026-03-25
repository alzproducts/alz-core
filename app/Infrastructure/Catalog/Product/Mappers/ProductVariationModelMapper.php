<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Product\Mappers;

use App\Domain\Catalog\Product\Resolvers\VariationPriceResolver;
use App\Domain\Catalog\Product\ValueObjects\Gtin;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationOption;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationView;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Inventory\ValueObjects\Weight;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\IntId;
use App\Domain\ValueObjects\TaxType;
use App\Infrastructure\Catalog\Product\Factories\ProductCostPriceFactory;
use App\Infrastructure\Catalog\Product\Models\ProductVariationModel;

/**
 * Single source of truth for ProductVariationModel ↔ Domain mapping.
 *
 * Three mapping paths (mirrors ProductModelMapper):
 * - `toDomain()`: Basic conversion (internal/write paths)
 * - `toViewDomain()`: API projection with domain types and Linnworks cost prices
 * - `toModelAttributes()`: Domain → Eloquent attributes (persistence)
 */
final class ProductVariationModelMapper
{
    public function __construct(
        private readonly ProductCostPriceFactory $costPriceFactory,
        private readonly VariationPriceResolver $priceResolver,
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
     * API projection: returns a domain-typed ProductVariationView with resolved prices.
     *
     * Resolves price/salePrice inheritance from parent, enriches cost price from Linnworks,
     * and wraps all values in domain types (Money, IntId, Sku, Weight).
     *
     * @param float $parentPrice Parent product's selling price (required fallback)
     * @param float|null $parentSalePrice Parent product's sale price
     * @param bool $vatExclusive Whether prices exclude VAT (from parent product)
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function toViewDomain(
        ProductVariationModel $model,
        float $parentPrice,
        ?float $parentSalePrice,
        bool $vatExclusive,
    ): ProductVariationView {
        $variation = self::toDomain($model);
        $resolved = $this->priceResolver->resolve(
            $variation,
            $parentPrice,
            parentCostPrice: null,
            parentSalePrice: $parentSalePrice,
        );

        $taxType = $vatExclusive ? TaxType::Exclusive : TaxType::Inclusive;

        return new ProductVariationView(
            id: IntId::from($model->external_id),
            sku: $model->sku !== null && \mb_trim($model->sku) !== '' ? Sku::fromTrusted(\mb_trim($model->sku)) : null,
            gtin: $model->gtin !== null ? Gtin::fromTrusted($model->gtin) : null,
            price: Money::fromTaxType($resolved->price, $taxType),
            costPrice: $model->sku !== null ? Money::nonZeroOrNull($this->costPriceFactory->getCostPrice($model->sku), $taxType) : null,
            salePrice: Money::nonZeroOrNull($resolved->salePrice, $taxType),
            stock: $model->stock,
            weight: $model->weight !== null ? Weight::kilogram($model->weight) : null,
            mpn: $model->mpn,
            imageIndex: $model->image_index,
            options: $variation->options,
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
