<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use App\Presentation\Http\Api\Traits\RejectsUnknownFieldKeysTrait;
use Spatie\LaravelData\Data;

/**
 * Request validation for PUT /api/products/{productId}.
 *
 * Validates the `fields` body payload as a key-value map with mixed types.
 * Unknown keys are rejected via RejectsUnknownFieldKeysTrait.
 */
final class UpdateProductFieldsRequestDTO extends Data
{
    use RejectsUnknownFieldKeysTrait;

    /**
     * @param array<string, mixed> $fields Field name => value pairs
     */
    public function __construct(
        public readonly array $fields,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'fields' => ['required', 'array', 'min:1'],
            'fields.title' => ['string', 'max:255'],
            'fields.description' => ['string'],
            'fields.meta_title' => ['string', 'max:255'],
            'fields.meta_description' => ['string', 'max:500'],
            'fields.categories' => ['array'],
            'fields.categories.*' => ['integer', 'min:1'],
            'fields.sort_order' => ['integer', 'min:0'],
        ];
    }

    /**
     * @return list<string>
     */
    protected static function allowedFieldKeys(): array
    {
        return ['title', 'description', 'meta_title', 'meta_description', 'categories', 'sort_order'];
    }
}
