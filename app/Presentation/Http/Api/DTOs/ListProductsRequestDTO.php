<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use App\Presentation\Http\Api\Enums\ProductIncludeEnum;
use App\Presentation\Http\Api\Traits\ValidatesIncludesTrait;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Request validation for GET /api/products.
 *
 * Validates pagination bounds and include parameter against an allowlist.
 */
final class ListProductsRequestDTO extends Data
{
    use ValidatesIncludesTrait;

    public function __construct(
        #[IntegerType, Min(1), Max(500)]
        public readonly int $per_page = 500,
        #[IntegerType, Min(1)]
        public readonly int $page = 1,
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
        return [ProductIncludeEnum::Variations->value];
    }
}
