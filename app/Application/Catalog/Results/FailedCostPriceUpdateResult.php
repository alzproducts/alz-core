<?php

declare(strict_types=1);

namespace App\Application\Catalog\Results;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\ValueObjects\Guid;

/**
 * Per-item failure in a bulk cost price update.
 *
 * Captures the SKU, error reason, and optionally the resolved stock item ID
 * (null when the SKU was never resolved to a Linnworks stock item).
 */
final readonly class FailedCostPriceUpdateResult
{
    public function __construct(
        public Sku $sku,
        public string $error,
        public ?Guid $stockItemId = null,
    ) {}
}
