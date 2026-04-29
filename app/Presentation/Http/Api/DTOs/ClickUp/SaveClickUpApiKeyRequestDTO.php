<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs\ClickUp;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * POST /api/clickup/api-key request body.
 *
 * Pass `?dry_run=true` as a query param to validate without persisting.
 */
final class SaveClickUpApiKeyRequestDTO extends Data
{
    public function __construct(
        #[Min(1), Max(500)]
        public readonly string $api_key,
    ) {}
}
