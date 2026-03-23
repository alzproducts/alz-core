<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Listeners;

use App\Domain\Catalog\Product\Events\ProductAddedToSaleEvent;
use App\Domain\Catalog\Product\Events\ProductRemovedFromSaleEvent;
use App\Infrastructure\Jobs\Linnworks\UpdateLinnworksSaleStateJob;

/**
 * Dispatches UpdateLinnworksSaleStateJob to manage is_in_sale and
 * last_sale_end_date EPs on Linnworks.
 *
 * Handles both ProductAddedToSaleEvent and ProductRemovedFromSaleEvent.
 */
final class UpdateLinnworksSaleStateListener
{
    public function handle(ProductAddedToSaleEvent|ProductRemovedFromSaleEvent $event): void
    {
        UpdateLinnworksSaleStateJob::dispatch(
            sku: $event->sku,
            addedToSale: $event instanceof ProductAddedToSaleEvent,
        );
    }
}
