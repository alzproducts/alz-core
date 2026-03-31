<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Product\Mappers;

use App\Domain\Catalog\Product\Enums\ProductSortField;

/**
 * Maps domain ProductSortField cases to catalog.products_view column names.
 *
 * Keeps the domain enum free from database schema knowledge while providing
 * the infrastructure layer with the correct ORDER BY column.
 */
final class ProductSortFieldMapper
{
    /**
     * Resolve the database column name for the given sort field.
     */
    public static function toColumn(ProductSortField $field): string
    {
        return match ($field) {
            ProductSortField::CreatedAt => 'shopwired_created_at',
            ProductSortField::UpdatedAt => 'shopwired_updated_at',
            ProductSortField::Title,
            ProductSortField::Price,
            ProductSortField::EffectivePrice,
            ProductSortField::Stock,
            ProductSortField::ProfitMargin => $field->value,
        };
    }
}
