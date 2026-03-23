<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Listeners;

use App\Domain\Catalog\Product\Events\ProductRemovedFromSaleEvent;
use App\Infrastructure\Jobs\Shopwired\UpdateShopwiredRemoveFromSaleJob;

/**
 * Dispatches UpdateShopwiredRemoveFromSaleJob when a product is removed from sale.
 *
 * Manages ShopWired sale category removal, sort order restore, and custom field cleanup.
 */
final readonly class RemoveProductFromSaleListener
{
    public function __construct(
        private int $saleCategoryId,
    ) {}

    public function handle(ProductRemovedFromSaleEvent $event): void
    {
        UpdateShopwiredRemoveFromSaleJob::dispatch(
            productId: $event->productId,
            saleCategoryId: $this->saleCategoryId,
        );
    }
}
