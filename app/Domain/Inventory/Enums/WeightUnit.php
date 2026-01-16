<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

/**
 * Unit of measurement for product weight.
 *
 * Matches Linnworks WeightUnitMetric enum values.
 * Used with Weight value object to represent product weights
 * in inventory data from Linnworks or other sources.
 */
enum WeightUnit: string
{
    case Kilogram = 'Kilogram';
    case Gram = 'Gram';

    /**
     * Convert a value from this unit to grams.
     */
    public function toGrams(float $value): float
    {
        return match ($this) {
            self::Kilogram => $value * 1000,
            self::Gram => $value,
        };
    }

    /**
     * Convert a value from this unit to kilograms.
     */
    public function toKilograms(float $value): float
    {
        return match ($this) {
            self::Kilogram => $value,
            self::Gram => $value / 1000,
        };
    }
}
