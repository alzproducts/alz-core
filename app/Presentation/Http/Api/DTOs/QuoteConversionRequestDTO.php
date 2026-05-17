<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\BeforeOrEqual;
use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Numeric;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Uuid;
use Spatie\LaravelData\Data;

/**
 * Request validation for POST /api/conversions/quote.
 *
 * Holds the wire-format string for `converted_at`; the use case parses it into
 * a `DateTimeImmutable`. `value` is GBP ex-VAT and must be positive — Google
 * Ads attributes revenue based on it.
 */
final class QuoteConversionRequestDTO extends Data
{
    public function __construct(
        #[Required, Uuid, MapInputName('submission_id')]
        public readonly string $submissionId,
        #[Required, Numeric, Min(0.01)]
        public readonly float $value,
        #[Required, StringType, Date, BeforeOrEqual('now'), MapInputName('converted_at')]
        public readonly string $convertedAt,
    ) {}
}
