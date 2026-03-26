<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Request validation for GET /api/products/{productId}/custom-fields.
 *
 * Optional `fields[]` query parameter to filter returned custom fields.
 */
final class GetProductCustomFieldsRequestDTO extends Data
{
    /**
     * @param list<string>|null $fields Optional field name filter
     */
    public function __construct(
        #[Nullable]
        public readonly ?array $fields = null,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'fields' => ['nullable', 'array', 'max:50'],
            'fields.*' => ['string', 'max:40'],
        ];
    }

    /**
     * @return list<string>
     */
    public function fieldNames(): array
    {
        return $this->fields ?? [];
    }
}
