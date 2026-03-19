<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

/**
 * A confirmed price change for a single SKU.
 *
 * Carries both previous and new pricing for downstream consumers
 * (Slack notifications, profit recalculation, audit logging).
 */
final readonly class SkuPriceChange
{
    public function __construct(
        public Sku $sku,
        public ProductRetailPricing $previousPrices,
        public ProductRetailPricing $newPrices,
    ) {}

    /**
     * Whether this change puts the SKU on sale (was not on sale, now is).
     */
    public function addedToSale(): bool
    {
        return ! $this->previousPrices->saleActive() && $this->newPrices->saleActive();
    }

    /**
     * Whether this change removes the SKU from sale (was on sale, now is not).
     */
    public function removedFromSale(): bool
    {
        return $this->previousPrices->saleActive() && ! $this->newPrices->saleActive();
    }

    /**
     * Whether the sale price changed (both before and after have an active sale).
     */
    public function saleChanged(): bool
    {
        return $this->previousPrices->saleActive()
            && $this->newPrices->saleActive()
            && $this->previousPrices->salePrice !== null
            && $this->newPrices->salePrice !== null
            && ! $this->previousPrices->salePrice->amountEquals($this->newPrices->salePrice);
    }
}
