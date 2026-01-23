<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use App\Domain\Exceptions\Data\InvalidGtinException;

/**
 * Global Trade Item Number (GTIN) Value Object.
 *
 * Represents a valid barcode identifier. Supports:
 * - GTIN-8 (EAN-8): 8 digits
 * - GTIN-12 (UPC-A): 12 digits
 * - GTIN-13 (EAN-13): 13 digits
 * - GTIN-14: 14 digits
 *
 * Validates format and check digit using GS1 standard algorithm.
 *
 * @see https://www.gs1.org/services/how-calculate-check-digit-manually
 */
final readonly class Gtin
{
    private const array VALID_LENGTHS = [8, 12, 13, 14];

    /**
     * @param string $value The raw GTIN string (digits only)
     */
    private function __construct(
        public string $value,
    ) {}

    /**
     * Create a GTIN from a string, validating format and check digit.
     *
     * @throws InvalidGtinException When GTIN is invalid
     */
    public static function fromString(string $value): self
    {
        // Remove any whitespace or dashes
        $normalized = \preg_replace('/[\s\-]/', '', $value);

        if (!\is_string($normalized)) {
            throw new InvalidGtinException($value, 'normalization failed');
        }

        // Must be digits only
        if (\preg_match('/^\d+$/', $normalized) !== 1) {
            throw new InvalidGtinException($value, 'must contain only digits');
        }

        // Must be valid length
        if (!\in_array(\mb_strlen($normalized), self::VALID_LENGTHS, true)) {
            throw new InvalidGtinException(
                $value,
                \sprintf('must be %s digits, got %d', \implode('/', self::VALID_LENGTHS), \mb_strlen($normalized)),
            );
        }

        // Validate check digit
        if (!self::isValidCheckDigit($normalized)) {
            throw new InvalidGtinException($value, 'invalid check digit');
        }

        return new self($normalized);
    }

    /**
     * Create a GTIN without validation.
     *
     * Use when data comes from a trusted source (e.g., database hydration).
     */
    public static function fromTrusted(string $value): self
    {
        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Validate the check digit using GS1 standard algorithm.
     *
     * Algorithm:
     * 1. Starting from the right (excluding check digit), alternate multipliers of 3 and 1
     * 2. Sum all weighted digits
     * 3. Check digit = (10 - (sum mod 10)) mod 10
     */
    private static function isValidCheckDigit(string $gtin): bool
    {
        $length = \mb_strlen($gtin);
        $checkDigit = (int) $gtin[$length - 1];

        $sum = 0;
        $multiplier = 3; // Start with 3 (rightmost digit before check)

        // Process right to left (excluding check digit)
        for ($i = $length - 2; $i >= 0; $i--) {
            $sum += (int) $gtin[$i] * $multiplier;
            $multiplier = $multiplier === 3 ? 1 : 3;
        }

        $calculatedCheck = (10 - ($sum % 10)) % 10;

        return $calculatedCheck === $checkDigit;
    }
}
