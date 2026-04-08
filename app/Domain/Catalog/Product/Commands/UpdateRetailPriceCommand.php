<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Commands;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Shared\Money\ValueObjects\Money;

final readonly class UpdateRetailPriceCommand
{
    public function __construct(
        public Sku $sku,
        public Money $rrp,
    ) {}
}
