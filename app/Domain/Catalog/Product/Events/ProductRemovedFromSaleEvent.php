<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Events;

use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
use App\Domain\ValueObjects\IntId;

/**
 * Domain event fired once per product when it is removed from sale.
 *
 * Dispatched by SaleStateDetectionService when any SkuPriceChange
 * in a ProductPricingUpdatedEvent indicates removedFromSale().
 *
 * Product-level: used by ShopWired listeners (category removal, custom field cleanup).
 * Per-SKU Linnworks updates use SkuRemovedFromSaleEvent instead.
 */
final readonly class ProductRemovedFromSaleEvent
{
    public function __construct(
        public IntId $productId,
        public SaleSettings $saleSettings,
    ) {}
}
