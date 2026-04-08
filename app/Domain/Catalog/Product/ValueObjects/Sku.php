<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use App\Domain\Exceptions\Data\InvalidSkuException;

/**
 * Validated Stock Keeping Unit (SKU).
 *
 * Validates SKUs with relaxed rules to support legacy data:
 * - Maximum 64 characters
 * - Any printable characters allowed (legacy SKUs contain spaces, slashes, etc.)
 * - No leading/trailing whitespace
 *
 * New SKUs generated via Linnworks `getNewItemNumber()` follow stricter
 * conventions by design, but we accept any existing SKU format.
 *
 * Use `fromString()` for user input (validates length).
 * Use `fromTrusted()` for database hydration (no validation).
 */
final readonly class Sku
{
    private const int MAX_LENGTH = 64;

    private function __construct(
        public string $value,
    ) {}

    /**
     * Create a SKU from user input with validation.
     *
     * @throws InvalidSkuException When SKU is empty or too long
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
