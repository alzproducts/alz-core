<?php

declare(strict_types=1);

namespace App\Application\Shopwired\PricingUpdate\Results;

use App\Domain\Catalog\Product\ValueObjects\Sku;

/**
 * A SKU whose prices were unchanged — skipped during pre-flight validation.
 */
final readonly class SkippedPriceUpdateResult
{
    public function __construct(
        public Sku $sku,
        public string $reason,
    ) {}
}
