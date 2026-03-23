<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Listeners;

use App\Domain\Catalog\Product\Events\ProductAddedToSaleEvent;
use App\Infrastructure\Jobs\Shopwired\UpdateShopwiredAddToSaleJob;

/**
 * Dispatches UpdateShopwiredAddToSaleJob when a product is added to sale.
 *
 * Manages ShopWired sale category, sort order, and custom fields.
 */
final readonly class AddProductToSaleListener
{
    public function __construct(
        private int $saleCategoryId,
    ) {}

    public function handle(ProductAddedToSaleEvent $event): void
    {
        UpdateShopwiredAddToSaleJob::dispatch(
            productId: $event->productId,
            saleSettings: $event->saleSettings,
            saleCategoryId: $this->saleCategoryId,
        );
    }
}
