<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use Illuminate\Validation\Validator;
use Spatie\LaravelData\Data;

/**
 * Request validation for PUT /api/categories/{categoryId}.
 *
 * Validates the `fields` body payload as a key-value map (string values only).
 * Unknown keys are rejected via an after-validation hook.
 */
final class UpdateCategoryFieldsRequestDTO extends Data
{
    private const array ALLOWED_KEYS = ['title', 'description', 'meta_title', 'meta_description'];

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

    public static function withValidator(Validator $validator): void
    {
        $validator->after(static function (Validator $validator): void {
            $fields = $validator->getValue('fields');

            if (! \is_array($fields)) {
                return;
            }

            /** @var array<string, mixed> $fields */
            $unknownKeys = \array_diff(\array_keys($fields), self::ALLOWED_KEYS);

            foreach ($unknownKeys as $key) {
                $validator->errors()->add('fields.' . $key, 'Unknown field: ' . $key);
            }
        });
    }
}
