<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\Uuid;
use Spatie\LaravelData\Data;

/**
 * Request validation for POST /api/conversions/lead.
 *
 * Maps the wire-format `submission_id` to the internal camelCase property.
 */
final class LeadConversionRequestDTO extends Data
{
    public function __construct(
        #[Required, Uuid, MapInputName('submission_id')]
        public readonly string $submissionId,
    ) {}
}
