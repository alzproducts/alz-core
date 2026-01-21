<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Concerns;

/**
 * Shared implementation for BasicProductInterface pricing methods.
 *
 * Provides isOnSale() and effectivePrice() implementations that work identically
 * for both Product and ProductVariation.
 *
 * Requires the using class to have $price and $salePrice properties.
 */
trait BasicProductTrait
{
    /**
     * Check if this item is currently on sale.
     */
    public function isOnSale(): bool
    {
        return $this->salePrice !== null && $this->salePrice < $this->price;
    }

    /**
     * Get the effective selling price (sale price if on sale, otherwise regular price).
     */
    public function effectivePrice(): float
    {
        if ($this->salePrice !== null && $this->salePrice < $this->price) {
            return $this->salePrice;
        }

        return $this->price;
    }
}
