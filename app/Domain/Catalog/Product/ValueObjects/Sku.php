<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use App\Domain\Exceptions\Data\InvalidSkuException;

/**
 * Validated Stock Keeping Unit (SKU).
 *
 * Enforces strict validation rules for new/updated SKUs:
 * - Maximum 40 characters
 * - Alphanumeric characters, hyphens, and underscores only
 * - No leading/trailing whitespace
 *
 * Use `fromString()` for user input (validates strictly).
 * Use `fromTrusted()` for database hydration (no validation).
 */
final readonly class Sku
{
    private const int MAX_LENGTH = 40;
    private const string VALID_PATTERN = '/^[A-Za-z0-9\-_]+$/';

    private function __construct(
        public string $value,
    ) {}

    /**
     * Create a SKU from user input with full validation.
     *
     * @throws InvalidSkuException When SKU format is invalid
     */
    public static function fromString(string $value): self
    {
        $trimmed = \mb_trim($value);

        if ($trimmed === '') {
            throw InvalidSkuException::empty();
        }

        if (\mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw InvalidSkuException::tooLong($trimmed, self::MAX_LENGTH);
        }

        if (\preg_match(self::VALID_PATTERN, $trimmed) !== 1) {
            throw InvalidSkuException::invalidCharacters($trimmed);
        }

        return new self($trimmed);
    }

    /**
     * Create a SKU from a trusted source (e.g., database).
     *
     * Skips validation - use only for data that has already been validated.
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
     * Check equality with another SKU (case-sensitive).
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
