<?php

declare(strict_types=1);

namespace App\Application\Conversion;

/**
 * Staff-supplied quote facts threaded from dispatch to upload: the GBP ex-VAT
 * amount and the raw (unparsed) conversion timestamp. Groups the two values that
 * are neither derived from the submission nor represented as domain types until
 * the command is built, keeping the pipeline entry points inside the parameter
 * limit.
 */
final readonly class QuoteConversionDetailsDTO
{
    public function __construct(
        public float $value,
        public string $convertedAt,
    ) {}
}
