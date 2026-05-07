<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Product\Mappers;

use App\Domain\Catalog\Product\ValueObjects\Popularity;
use App\Domain\Catalog\Product\ValueObjects\ProductSupplier;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationOption;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationView;
use App\Infrastructure\Catalog\Product\Models\ProductVariationViewModel;
use App\Infrastructure\Linnworks\Models\StockItemSupplierModel;

/**
 * Maps ProductVariationViewModel (read-only view model) to domain ProductVariationView.
 *
 * Handles API projection from the SQL view with resolved prices and Linnworks cost.
 * Supplier resolution (navigating Eloquent relations to extract domain VOs) also lives here.
 */
final class ProductVariationViewModelMapper
{
    /**
     * API projection: returns a domain-typed ProductVariationView from a view model.
     *
     * Passes primitives directly — the VO self-constructs domain types.
     * Prices are already resolved (parent inheritance applied in SQL via COALESCE).
     * Supplier data is pre-resolved by the assembler and passed through.
     *
     * @param bool $vatExclusive Whether prices exclude VAT (from parent product)
     * @param ProductSupplier|null $defaultSupplier Pre-resolved default supplier
     * @param list<ProductSupplier>|null $suppliers Pre-resolved suppliers (null = not requested)
     */
    public function toViewDomain(
        ProductVariationViewModel $model,
        bool $vatExclusive,
        ?ProductSupplier $defaultSupplier = null,
        ?array $suppliers = null,
    ): ProductVariationView {
        $stockItem = $model->relationLoaded('stockItem') ? $model->stockItem : null;

        return new ProductVariationView(
            externalId: $model->external_id,
            sku: $model->sku,
            gtin: $model->gtin,
            price: $model->price,
            costPrice: $model->cost_price,
            salePrice: $model->sale_price,
            rrp: $model->extraData?->rrp,
            effectivePrice: $model->effective_price,
            isOnSale: $model->is_on_sale,
            profitMargin: $model->profit_margin,
            availableStock: $model->available_stock,
            physicalStock: $model->physical_stock,
            weight: $model->weight,
            vatExclusive: $vatExclusive,
            mpn: $model->mpn,
            imageIndex: $model->image_index,
            options: self::buildOptions($model->options),
            createdAt: $model->created_at->toDateTimeImmutable(),
            updatedAt: $model->updated_at->toDateTimeImmutable(),
            defaultSupplier: $defaultSupplier,
            suppliers: $suppliers,
            isComposite: $stockItem !== null && $stockItem->is_composite,
            inventory: $stockItem?->toProductInventory(),
            popularity: Popularity::fromRank($model->popularity_rank, $model->popularity_max),
            stockValue: $model->stock_value,
        );
    }

    public static function resolveDefaultSupplier(ProductVariationViewModel $model): ?ProductSupplier
    {
        $stockItem = $model->relationLoaded('stockItem') ? $model->stockItem : null;

        return $stockItem?->defaultSupplier()?->toProductSupplier();
    }

    /** @return list<ProductSupplier> */
    public static function resolveSuppliers(ProductVariationViewModel $model): array
    {
        if (! $model->relationLoaded('stockItem') || $model->stockItem === null) {
            return [];
        }

        return \array_values($model->stockItem->suppliers
            ->sortByDesc('is_default')
            ->map(static fn(StockItemSupplierModel $s): ProductSupplier => $s->toProductSupplier())
            ->all());
    }

    /**
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
