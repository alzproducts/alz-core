<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Commands;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Shared\Money\ValueObjects\Money;

/**
 * Command to update a product's cost price for a specific supplier.
 *
 * Updates both the Linnworks supplier purchase price via API and the
 * local stock_item_suppliers record for immediate consistency.
 */
final readonly class UpdateCostPriceCommand
{
    public function __construct(
        public Sku $sku,
        public Money $costPrice,
        public string $supplierName,
    ) {}
}
