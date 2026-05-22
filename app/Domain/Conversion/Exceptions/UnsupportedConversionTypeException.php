<?php

declare(strict_types=1);

namespace App\Domain\Conversion\Exceptions;

use App\Domain\Conversion\Enums\ConversionType;
use LogicException;

/**
 * Thrown when a ConversionType is not supported by the target ad platform.
 *
 * Extends LogicException because each platform's supported types are part of
 * its compile-time contract — a caller passing an unsupported type is a
 * programming error (e.g. dispatching QuoteIssued to Bing, which only
 * supports LeadReceived).
 */
final class UnsupportedConversionTypeException extends LogicException
{
    public function __construct(
        public readonly ConversionType $conversionType,
        public readonly string $platform,
    ) {
        parent::__construct('Conversion type not supported by ad platform');
    }

    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        return [
            'conversion_type' => $this->conversionType->value,
            'platform' => $this->platform,
        ];
    }
}
