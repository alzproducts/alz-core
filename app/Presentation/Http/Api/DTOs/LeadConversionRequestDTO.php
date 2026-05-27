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
 * `id` is source-agnostic — the orchestrator resolves whether it identifies a
 * contact submission or a call. `is_potential_quote` is the staff classification
 * captured at submit time so the dashboard's Awaiting Quote view populates
 * immediately.
 */
final class LeadConversionRequestDTO extends Data
{
    public function __construct(
        #[Required, Uuid, MapInputName('id')]
        public readonly string $id,
        #[Required, BooleanType, MapInputName('is_potential_quote')]
        public readonly bool $isPotentialQuote,
    ) {}
}
