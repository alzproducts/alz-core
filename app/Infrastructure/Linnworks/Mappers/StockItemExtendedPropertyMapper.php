<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Mappers;

use App\Domain\Inventory\ValueObjects\StockItemExtendedProperty;
use App\Infrastructure\Linnworks\Models\StockItemExtendedPropertyModel;

/**
 * Maps between StockItemExtendedPropertyModel (Eloquent) and StockItemExtendedProperty (Domain).
 */
final class StockItemExtendedPropertyMapper
{
    /**
     * Convert Eloquent model to Domain StockItemExtendedProperty.
     */
    public static function fromModel(StockItemExtendedPropertyModel $model): StockItemExtendedProperty
    {
        return new StockItemExtendedProperty(
            rowId: $model->pk_row_id,
            name: $model->property_name,
            value: $model->property_value,
            type: $model->property_type,
        );
    }

    /**
     * Convert Domain StockItemExtendedProperty to Eloquent model attributes.
     *
     * Note: Does NOT include 'stock_item_id' - that's set by the repository
     * when inserting EPs for a specific stock item.
     *
     * @return array<string, mixed>
     */
    public static function toModelAttributes(StockItemExtendedProperty $property): array
    {
        return [
            'pk_row_id' => $property->rowId,
            'property_name' => $property->name,
            'property_value' => $property->value,
            'property_type' => $property->type,
        ];
    }
}
