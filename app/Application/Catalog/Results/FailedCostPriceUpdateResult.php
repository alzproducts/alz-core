<?php

declare(strict_types=1);

namespace App\Application\Catalog\Results;

use App\Domain\Catalog\Product\ValueObjects\Sku;

/**
 * Per-item failure in a bulk cost price update.
 *
 * Captures the SKU and the reason it failed (e.g., "SKU not found in Linnworks").
 */
final readonly class FailedCostPriceUpdateResult
{
    public function __construct(
        public Sku $sku,
        public string $error,
    ) {}
}
