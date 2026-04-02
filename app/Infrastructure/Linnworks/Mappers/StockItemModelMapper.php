<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Mappers;

use App\Domain\Inventory\Enums\WeightUnit;
use App\Domain\Inventory\ValueObjects\Dimensions;
use App\Domain\Inventory\ValueObjects\StockItemFull;
use App\Domain\Inventory\ValueObjects\Weight;
use App\Infrastructure\Linnworks\Models\StockItemExtendedPropertyModel;
use App\Infrastructure\Linnworks\Models\StockItemModel;
use App\Infrastructure\Linnworks\Models\StockItemSupplierModel;

/**
 * Maps between StockItemModel (Eloquent) and StockItemFull (Domain).
 */
final class StockItemModelMapper
{
    /**
     * Convert Eloquent model to Domain StockItemFull.
     */
    public static function fromModel(StockItemModel $model): StockItemFull
    {
        return new StockItemFull(
            stockItemId: $model->stock_item_id,
            sku: $model->item_number,
            title: $model->item_title,
            barcode: $model->barcode ?? '',
            quantity: $model->quantity ?? 0,
            available: $model->available ?? 0,
            inOrder: $model->in_order ?? 0,
            due: $model->due ?? 0,
            minimumLevel: $model->minimum_level ?? 0,
            jit: $model->jit,
            purchasePrice: $model->purchase_price ?? 0.0,
            retailPrice: $model->retail_price ?? 0.0,
            taxRate: $model->tax_rate,
            weight: new Weight(
                $model->weight ?? 0.0,
                WeightUnit::tryFrom($model->weight_unit ?? '') ?? WeightUnit::Kilogram,
            ),
            dimensions: new Dimensions(
                $model->height ?? 0.0,
                $model->width ?? 0.0,
                $model->depth ?? 0.0,
            ),
            isComposite: $model->is_composite,
            categoryId: $model->category_id,
            categoryName: $model->category_name,
            createdAt: $model->linnworks_created_at?->toDateTimeImmutable(),
            extendedProperties: $model->relationLoaded('extendedProperties')
                ? \array_values(\array_map(
                    static fn(StockItemExtendedPropertyModel $ep) => $ep->toDomain(),
                    $model->extendedProperties->all(),
                ))
                : [],
            suppliers: $model->relationLoaded('suppliers')
                ? \array_values(\array_map(
                    static fn(StockItemSupplierModel $s) => $s->toDomain(),
                    $model->suppliers->all(),
                ))
                : [],
        );
    }

    /**
     * Convert Domain StockItemFull to Eloquent model attributes.
     *
     * @return array<string, mixed>
     */
    public static function toModelAttributes(StockItemFull $stockItem): array
    {
        return [
            'stock_item_id' => $stockItem->stockItemId,
            'item_number' => $stockItem->sku,
            'item_title' => $stockItem->title,
            'barcode' => $stockItem->barcode,
            'quantity' => $stockItem->quantity,
            'available' => $stockItem->available,
            'in_order' => $stockItem->inOrder,
            'due' => $stockItem->due,
            'minimum_level' => $stockItem->minimumLevel,
            'jit' => $stockItem->jit,
            'purchase_price' => $stockItem->purchasePrice,
            'retail_price' => $stockItem->retailPrice,
            'tax_rate' => $stockItem->taxRate,
            'weight' => $stockItem->weight->value,
            'weight_unit' => $stockItem->weight->unit->value,
            'height' => $stockItem->dimensions->height,
            'width' => $stockItem->dimensions->width,
            'depth' => $stockItem->dimensions->depth,
            'is_composite' => $stockItem->isComposite,
            'category_id' => $stockItem->categoryId,
            'category_name' => $stockItem->categoryName,
            'linnworks_created_at' => $stockItem->createdAt,
        ];
    }
}
