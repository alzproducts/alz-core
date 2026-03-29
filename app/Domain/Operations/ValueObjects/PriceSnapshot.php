<?php

declare(strict_types=1);

namespace App\Domain\Operations\ValueObjects;

use App\Domain\Catalog\Product\ValueObjects\Sku;

/**
 * A point-in-time snapshot of a SKU's pricing for SCD2 period tracking.
 *
 * Groups the five pricing fields needed to record a price change
 * in the price period repository.
 */
final readonly class PriceSnapshot
{
    public function __construct(
        public Sku $sku,
        public float $basePriceGross,
        public ?float $salePriceGross,
        public float $effectivePriceGross,
        public bool $priceHasTax,
    ) {}
}
