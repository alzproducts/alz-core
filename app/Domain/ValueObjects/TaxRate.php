<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Tax rate percentage for a product or service.
 *
 * Represents the VAT/tax rate that applies to an item. Stored as a percentage
 * (e.g., 20.0 for 20% VAT) and provides conversion to decimal for calculations.
 *
 * Common UK rates:
 * - Standard: 20%
 * - Zero-rated: 0% (children's clothing, books, etc.)
 */
final readonly class TaxRate
{
    private const float UK_STANDARD_RATE = 20.0;

    private function __construct(
        public float $percentage,
    ) {
        Assert::greaterThanEq($percentage, 0, 'Tax rate cannot be negative');
    }

    /**
     * Create from a percentage value.
     *
     * @param float $percentage The rate as a percentage (e.g., 20.0 for 20%)
     */
    public static function fromPercentage(float $percentage): self
    {
        return new self($percentage);
    }

    /**
     * Create from a decimal value.
     *
     * @param float $decimal The rate as a decimal (e.g., 0.20 for 20%)
     */
    public static function fromDecimal(float $decimal): self
    {
        return new self($decimal * 100);
    }

    /**
     * UK standard VAT rate (20%).
     */
    public static function standard(): self
    {
        return new self(self::UK_STANDARD_RATE);
    }

    /**
     * Zero-rated (0%).
     *
     * For items exempt from VAT (children's clothing, books, etc.).
     */
    public static function zero(): self
    {
        return new self(0.0);
    }

    /**
     * Get the rate as a decimal for calculations.
     *
     * @return float The rate as decimal (e.g., 0.20 for 20%)
     */
    public function toDecimal(): float
    {
        return $this->percentage / 100;
    }

    /**
     * Check if this is a zero rate.
     */
    public function isZero(): bool
    {
        return $this->percentage === 0.0;
    }

    /**
     * Check if this is the standard UK rate.
     */
    public function isStandard(): bool
    {
        return $this->percentage === self::UK_STANDARD_RATE;
    }
}
