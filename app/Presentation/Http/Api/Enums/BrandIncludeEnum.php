<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Enums;

/**
 * Allowed include values for brand API endpoints.
 *
 * Used in request DTOs for validation and in detail resources for serialization.
 * Inner layers receive the string value via ->value.
 */
enum BrandIncludeEnum: string
{
    case Description = 'description';
    case CustomFields = 'custom_fields';

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
