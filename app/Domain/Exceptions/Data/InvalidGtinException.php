<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Data;

/**
 * GTIN (barcode) validation failed.
 *
 * Thrown when a GTIN string fails format or check digit validation.
 * Valid formats: GTIN-8, GTIN-12, GTIN-13, GTIN-14.
 */
final class InvalidGtinException extends AbstractDataException
{
    public function __construct(
        public readonly string $value,
        public readonly string $reason,
    ) {
        parent::__construct("Invalid GTIN '{$value}': {$reason}");
    }
}
