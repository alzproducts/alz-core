<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Commands;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Shared\Money\ValueObjects\Money;

/**
 * Per-item command for a bulk cost price update.
 *
 * The supplier context is provided at the batch level by the UseCase,
 * not per-item — keeping commands focused on the SKU-level data.
 */
final readonly class UpdateCostPriceCommand
{
    public function __construct(
        public Sku $sku,
        public Money $costPrice,
    ) {}
}
