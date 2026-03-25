<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use App\Presentation\Http\Api\Traits\ValidatesIncludesTrait;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Request validation for GET /api/products/{productId}.
 *
 * Validates the optional `include` query parameter against an allowlist.
 */
final class ShowProductRequestDTO extends Data
{
    use ValidatesIncludesTrait;

    public function __construct(
        #[Nullable, StringType]
        public readonly ?string $include = null,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'include' => self::includeRules(),
        ];
    }

    /**
     * @return list<string>
     */
    public static function allowedIncludes(): array
    {
        return ['variations', 'description', 'category_ids', 'custom_fields', 'filters'];
    }
}
