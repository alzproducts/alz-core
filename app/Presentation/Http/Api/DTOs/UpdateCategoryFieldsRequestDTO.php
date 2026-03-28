<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use App\Presentation\Http\Api\Traits\RejectsUnknownFieldKeysTrait;
use Spatie\LaravelData\Data;

/**
 * Request validation for PUT /api/categories/{categoryId}.
 *
 * Validates the `fields` body payload as a key-value map (string values only).
 * Unknown keys are rejected via RejectsUnknownFieldKeysTrait.
 */
final class UpdateCategoryFieldsRequestDTO extends Data
{
    use RejectsUnknownFieldKeysTrait;

    /**
     * @param array<string, string> $fields Field name => value pairs
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
        ];
    }

    /**
     * @return list<string>
     */
    protected static function allowedFieldKeys(): array
    {
        return ['title', 'description', 'meta_title', 'meta_description'];
    }
}
