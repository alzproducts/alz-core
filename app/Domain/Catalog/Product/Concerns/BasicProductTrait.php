<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Concerns;

use App\Domain\Catalog\Product\ValueObjects\Product;

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
        return Product::isSaleActive($this->salePrice, $this->price);
    }

    /**
     * Get the effective selling price (sale price if on sale, otherwise regular price).
     */
    public function effectivePrice(): float
    {
        if (Product::isSaleActive($this->salePrice, $this->price)) {
            /** @var float $salePrice PHPStan: isSaleActive guarantees non-null, positive salePrice */
            $salePrice = $this->salePrice;

            return $salePrice;
        }

        return $this->price;
    }
}
