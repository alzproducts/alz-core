<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Events;

use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
use App\Domain\ValueObjects\IntId;

/**
 * Domain event fired once per product when it is added to sale.
 *
 * Dispatched by SaleStateDetectionService when any SkuPriceChange
 * in a ProductPricingUpdatedEvent indicates addedToSale().
 *
 * Product-level: used by ShopWired listeners (category, custom fields, sort order).
 * Per-SKU Linnworks updates use SkuAddedToSaleEvent instead.
 */
final readonly class ProductAddedToSaleEvent
{
    public function __construct(
        public IntId $productId,
        public SaleSettings $saleSettings,
    ) {}
}
