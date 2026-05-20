<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\BooleanType;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\Uuid;
use Spatie\LaravelData\Data;

/**
 * Request validation for POST /api/conversions/lead.
 *
 * Maps the wire-format `submission_id` / `is_potential_quote` to internal camelCase properties.
 * `is_potential_quote` is the staff's at-conversion-time classification — captured atomically
 * with the lead action so the dashboard's Awaiting Quote view is populated as soon as the lead
 * is recorded.
 */
final class LeadConversionRequestDTO extends Data
{
    public function __construct(
        #[Required, Uuid, MapInputName('submission_id')]
        public readonly string $submissionId,
        #[Required, BooleanType, MapInputName('is_potential_quote')]
        public readonly bool $isPotentialQuote,
    ) {}
}
