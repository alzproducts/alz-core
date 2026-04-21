<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use App\Domain\Shared\Money\ValueObjects\Money;

/**
 * Master-product pricing inputs for {@see ProductViewPricing::aggregate()}.
 *
 * `costPrice` is nullable because variant-only masters often lack a master-level cost.
 */
final readonly class MasterPricing
{
    public function __construct(
        public Money $price,
        public Money $effectivePrice,
        public ?Money $costPrice,
        public ?float $profitMargin,
    ) {}
}
