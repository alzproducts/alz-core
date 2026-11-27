<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;

/**
 * Carries no name: the stored name is always the candidate's QuickBooks name.
 */
final class UpdateQuickBooksMappingRequestDTO extends Data
{
    public function __construct(
        #[Required, StringType, Min(1), Max(255)]
        public readonly string $ref_id,
    ) {}
}
