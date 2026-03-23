<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Events;

use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\ValueObjects\IntId;

/**
 * Domain event fired when a product is added to sale.
 *
 * Dispatched by DetectSaleStateChangeListener when any SkuPriceChange
 * in a ProductPricingUpdatedEvent indicates addedToSale().
 *
 * Listeners: UpdateShopwiredSaleStateListener, UpdateLinnworksSaleStateListener.
 */
final readonly class ProductAddedToSaleEvent
{
    public function __construct(
        public IntId $productId,
        public Sku $sku,
        public SaleSettings $saleSettings,
    ) {}
}
