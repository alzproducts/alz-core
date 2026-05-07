<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Product\Mappers;

use App\Domain\Catalog\Product\Enums\VariationSortField;

/**
 * Maps domain VariationSortField cases to catalog.product_variations_view column names.
 */
final class VariationSortFieldMapper
{
    public static function toColumn(VariationSortField $field): string
    {
        return match ($field) {
            VariationSortField::Price,
            VariationSortField::EffectivePrice,
            VariationSortField::ProfitMargin => $field->value,
            VariationSortField::Stock => 'available_stock',
            VariationSortField::CreatedAt => 'created_at',
            VariationSortField::UpdatedAt => 'updated_at',
            VariationSortField::Popularity => 'popularity_rank',
        };
    }
}
