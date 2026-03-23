<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Events;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\ValueObjects\IntId;

/**
 * Domain event fired per-SKU when a product variation is added to sale.
 *
 * Dispatched by SaleStateDetectionService for each SKU that addedToSale().
 * Used by Linnworks listeners that need per-SKU EP updates (is_in_sale).
 *
 * Contrast with ProductAddedToSaleEvent which is product-level (1 per product)
 * and used by ShopWired listeners for category/custom field updates.
 */
final readonly class SkuAddedToSaleEvent
{
    public function __construct(
        public IntId $productId,
        public Sku $sku,
    ) {}
}
