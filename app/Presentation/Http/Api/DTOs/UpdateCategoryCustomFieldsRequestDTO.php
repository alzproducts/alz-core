<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use Spatie\LaravelData\Data;

/**
 * Request validation for POST /api/categories/{categoryId}/custom-fields.
 *
 * Validates the `custom_fields` body payload as a key-value map.
 */
final class UpdateCategoryCustomFieldsRequestDTO extends Data
{
    /**
     * @param array<string, string|int|bool|list<string>|list<int>|null> $custom_fields Field name => value pairs
     */
    public function __construct(
        public readonly array $custom_fields,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'custom_fields' => ['required', 'array', 'min:1'],
        ];
    }
}
