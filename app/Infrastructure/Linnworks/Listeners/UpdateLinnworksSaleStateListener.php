<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Listeners;

use App\Domain\Catalog\Product\Events\SkuAddedToSaleEvent;
use App\Domain\Catalog\Product\Events\SkuRemovedFromSaleEvent;
use App\Infrastructure\Jobs\Linnworks\UpdateLinnworksSaleStateJob;

/**
 * Dispatches UpdateLinnworksSaleStateJob to manage is_in_sale and
 * last_sale_end_date EPs on Linnworks.
 *
 * Listens to per-SKU sale events so each variation gets its own EP update.
 */
final class UpdateLinnworksSaleStateListener
{
    public function handle(SkuAddedToSaleEvent|SkuRemovedFromSaleEvent $event): void
    {
        UpdateLinnworksSaleStateJob::dispatch(
            sku: $event->sku,
            addedToSale: $event instanceof SkuAddedToSaleEvent,
        );
    }
}
