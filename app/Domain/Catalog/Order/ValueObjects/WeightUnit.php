<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Order\ValueObjects;

/**
 * Weight unit for order/product weights.
 */
enum WeightUnit: string
{
    case Kilograms = 'kg';

    /**
     * Convert a weight value to grams.
     */
    public function toGrams(float $value): float
    {
        return match ($this) {
            self::Kilograms => $value * 1000,
        };
    }
}
