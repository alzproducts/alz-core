<?php

declare(strict_types=1);

namespace App\Domain\Inventory\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Physical dimensions of an inventory item.
 *
 * Represents height, width, and depth measurements. All values
 * are in a consistent unit (typically centimeters from Linnworks).
 * Zero dimensions are allowed (some items may not have measurements).
 *
 * @template-pattern Domain Value Object
 */
final readonly class Dimensions
{
    public function __construct(
        public float $height,
        public float $width,
        public float $depth,
    ) {
        Assert::greaterThanEq($height, 0, 'Height cannot be negative');
        Assert::greaterThanEq($width, 0, 'Width cannot be negative');
        Assert::greaterThanEq($depth, 0, 'Depth cannot be negative');
    }

    /**
     * Create a zero-dimension instance for items without measurements.
     */
    public static function zero(): self
    {
        return new self(0.0, 0.0, 0.0);
    }

    /**
     * Check if all dimensions are zero (unmeasured item).
     */
    public function isEmpty(): bool
    {
        return $this->height === 0.0
            && $this->width === 0.0
            && $this->depth === 0.0;
    }

    /**
     * Calculate cubic volume (height × width × depth).
     */
    public function volume(): float
    {
        return $this->height * $this->width * $this->depth;
    }
}
