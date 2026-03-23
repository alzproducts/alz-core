<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Events;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\ValueObjects\IntId;

/**
 * Domain event fired per-SKU when a product variation is removed from sale.
 *
 * Dispatched by SaleStateDetectionService for each SKU that removedFromSale().
 * Used by Linnworks listeners that need per-SKU EP updates (is_in_sale, last_sale_end_date).
 *
 * Contrast with ProductRemovedFromSaleEvent which is product-level (1 per product)
 * and used by ShopWired listeners for category/custom field cleanup.
 */
final readonly class SkuRemovedFromSaleEvent
{
    public function __construct(
        public IntId $productId,
        public Sku $sku,
    ) {}
}
