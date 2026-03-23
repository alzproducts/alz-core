<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Listeners;

use App\Domain\Catalog\Product\Events\SkuRetailPricingUpdatedEvent;
use App\Infrastructure\Jobs\Linnworks\UpdateLinnworksSellingPriceEpsJob;

/**
 * Dispatches UpdateLinnworksSellingPriceEpsJob to sync SellingPriceGross
 * and SellingPriceNet EPs to Linnworks on price update.
 */
final class UpdateLinnworksSellingPriceEpsListener
{
    public function handle(SkuRetailPricingUpdatedEvent $event): void
    {
        UpdateLinnworksSellingPriceEpsJob::dispatch(
            sku: $event->sku,
            effectivePrice: $event->newPrices->effectivePrice(),
        );
    }
}
