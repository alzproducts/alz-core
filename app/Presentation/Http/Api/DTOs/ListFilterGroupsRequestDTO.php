<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * Request validation for GET /api/filter-groups.
 *
 * Validates pagination bounds only (no includes needed).
 */
final class ListFilterGroupsRequestDTO extends Data
{
    public function __construct(
        #[IntegerType, Min(1), Max(500)]
        public readonly int $per_page = 500,
        #[IntegerType, Min(1)]
        public readonly int $page = 1,
    ) {}
}
