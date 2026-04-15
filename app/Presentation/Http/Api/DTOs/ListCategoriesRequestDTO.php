<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use Spatie\LaravelData\Attributes\Validation\BooleanType;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Data;

/**
 * Request validation for GET /api/categories.
 *
 * Validates pagination bounds and include_inactive filter.
 * No includes supported on list endpoint (use show for embeds).
 */
final class ListCategoriesRequestDTO extends Data
{
    public function __construct(
        #[IntegerType, Min(1), Max(1000)]
        public readonly int $per_page = 500,
        #[IntegerType, Min(1)]
        public readonly int $page = 1,
        #[Nullable, BooleanType]
        public readonly bool $include_inactive = false,
        #[Nullable, BooleanType]
        public readonly ?bool $is_main_category = null,
    ) {}
}
