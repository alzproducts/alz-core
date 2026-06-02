<?php

declare(strict_types=1);

namespace App\Application\Catalog\Commands;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\Guid;

/**
 * A single old → new supplier cost-price delta to be recorded in the audit log.
 *
 * Carries the Linnworks supplier GUID (stable key) plus the denormalised supplier name.
 */
final readonly class CostPriceChangeCommand
{
    public function __construct(
        public Sku $sku,
        public Guid $supplierId,
        public string $supplierName,
        public Money $oldPrice,
        public Money $newPrice,
    ) {}
}
