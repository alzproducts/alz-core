<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Events;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\ValueObjects\IntId;

/**
 * Domain event fired after all SKU price updates for a product are confirmed.
 *
 * Dispatched once per product after all per-SKU events.
 * Carries the product ID and the list of SKUs that were updated.
 *
 * Listeners (future): profit recalculation, Slack notification.
 */
final readonly class ProductPricingUpdatedEvent
{
    /**
     * @param IntId $productId ShopWired product external ID
     * @param list<Sku> $updatedSkus SKUs that were confirmed updated
     */
    public function __construct(
        public IntId $productId,
        public array $updatedSkus,
    ) {}
}
