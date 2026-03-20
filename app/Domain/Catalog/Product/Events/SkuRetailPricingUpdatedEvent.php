<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Events;

use App\Domain\Catalog\Product\ValueObjects\ProductRetailPricing;
use App\Domain\Catalog\Product\ValueObjects\Sku;

/**
 * Domain event fired when a single SKU's retail pricing is confirmed updated.
 *
 * Dispatched once per SKU that receives updated: true from the API.
 * Carries both previous and new pricing for SCD2 period recording.
 *
 * Listeners: RecordPricePeriodListener (SCD2 history).
 */
final readonly class SkuRetailPricingUpdatedEvent
{
    public function __construct(
        public Sku $sku,
        public ProductRetailPricing $previousPrices,
        public ProductRetailPricing $newPrices,
    ) {}
}
