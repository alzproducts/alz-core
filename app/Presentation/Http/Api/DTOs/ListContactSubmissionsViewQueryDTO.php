<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * Request validation for the per-view GET /api/contact-submissions/{view} endpoints.
 *
 * The view itself is encoded in the URL path — this DTO only carries pagination.
 * Filter criteria live in the backend view definitions, not the query string.
 *
 * `per_page` is capped at PageRequest::MAX_PER_PAGE (1000) — higher than the legacy
 * generic-list DTO's 100 because the named views are pre-filtered to a single workflow
 * stage and staff routinely export the full Completed/Failed list as one page.
 */
final class ListContactSubmissionsViewQueryDTO extends Data
{
    public function __construct(
        #[IntegerType, Min(1), Max(1000)]
        public readonly int $per_page = 50,
        #[IntegerType, Min(1)]
        public readonly int $page = 1,
    ) {}
}
