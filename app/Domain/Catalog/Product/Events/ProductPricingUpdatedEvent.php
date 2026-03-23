<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Events;

use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
use App\Domain\Catalog\Product\ValueObjects\SkuPriceChange;
use App\Domain\ValueObjects\IntId;

/**
 * Domain event fired after all SKU price updates for a product are confirmed.
 *
 * Dispatched once per product after all per-SKU events.
 * Carries the product ID and per-SKU price changes for downstream
 * listeners (Slack notification, profit recalculation).
 */
final readonly class ProductPricingUpdatedEvent
{
    /**
     * @param IntId $productId ShopWired product external ID
     * @param list<SkuPriceChange> $priceChanges Confirmed price changes per SKU
     * @param SaleSettings|null $saleSettings Optional sale metadata for downstream listeners
     */
    public function __construct(
        public IntId $productId,
        public array $priceChanges,
        public ?SaleSettings $saleSettings = null,
    ) {}
}
