<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Enums;

/**
 * Allowed include values for product API endpoints.
 *
 * Used in request DTOs for validation and in detail resources for serialization.
 * Inner layers receive the string value via ->value.
 */
enum ProductIncludeEnum: string
{
    case Variations = 'variations';
    case Description = 'description';
    case CategoryIds = 'category_ids';
    case CustomFields = 'custom_fields';
    case Filters = 'filters';
    case SaleSettings = 'sale_settings';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(
            static fn(self $case): string => $case->value,
            self::cases(),
        );
    }
}
