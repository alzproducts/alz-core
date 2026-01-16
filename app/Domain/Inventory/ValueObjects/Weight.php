<?php

declare(strict_types=1);

namespace App\Domain\Inventory\ValueObjects;

use App\Domain\Inventory\Enums\WeightUnit;
use Webmozart\Assert\Assert;

/**
 * Weight measurement with unit for inventory items.
 *
 * Represents a weight value with its unit of measurement,
 * supporting conversions between kilograms and grams.
 * Zero weight is allowed (some items may not have weights recorded).
 *
 * @template-pattern Domain Value Object
 */
final readonly class Weight
{
    public function __construct(
        public float $value,
        public WeightUnit $unit,
    ) {
        Assert::greaterThanEq($value, 0, 'Weight cannot be negative');
    }

    /**
     * Create a zero-weight instance.
     */
    public static function zero(WeightUnit $unit = WeightUnit::Kilogram): self
    {
        return new self(0.0, $unit);
    }

    /**
     * Create from kilograms.
     */
    public static function kilogram(float $value): self
    {
        return new self($value, WeightUnit::Kilogram);
    }

    /**
     * Create from grams.
     */
    public static function gram(float $value): self
    {
        return new self($value, WeightUnit::Gram);
    }

    /**
     * Check if weight is zero.
     */
    public function isEmpty(): bool
    {
        return $this->value === 0.0;
    }

    /**
     * Get weight in grams.
     */
    public function inGrams(): float
    {
        return $this->unit->toGrams($this->value);
    }

    /**
     * Get weight in kilograms.
     */
    public function inKilograms(): float
    {
        return $this->unit->toKilograms($this->value);
    }

    /**
     * Convert to a different unit.
     */
    public function convertTo(WeightUnit $targetUnit): self
    {
        if ($this->unit === $targetUnit) {
            return $this;
        }

        $valueInGrams = $this->inGrams();
        $convertedValue = $targetUnit === WeightUnit::Gram
            ? $valueInGrams
            : $valueInGrams / 1000;

        return new self($convertedValue, $targetUnit);
    }
}
