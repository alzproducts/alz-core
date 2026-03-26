<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use Spatie\LaravelData\Data;

/**
 * Request validation for custom field write endpoints.
 *
 * Shared across products, categories, and brands — the payload
 * structure is identical for all entity types.
 */
final class UpdateCustomFieldsRequestDTO extends Data
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
