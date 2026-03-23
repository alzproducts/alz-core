<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Resolved prices for a product variation.
 *
 * Contains final price values after resolving null values to parent defaults.
 *
 * **Cost Price Semantics:**
 * - Cost price is nullable (null = unknown/not tracked)
 * - Zero cost price (0.00) is NOT valid - items always have some cost
 * - Only price and salePrice can be 0.00 (e.g., "temporarily removed from sale")
 *
 * @see VariationPriceResolver for resolution logic
 */
final readonly class ResolvedVariationPrices
{
    /**
     * @param float $price Selling price (resolved from variation or parent)
     * @param float|null $costPrice Cost/wholesale price (null = unknown, must be > 0 if set)
     * @param float|null $salePrice Discounted price (null = no sale)
     */
    public function __construct(
        public float $price,
        public ?float $costPrice,
        public ?float $salePrice,
    ) {
        Assert::greaterThanEq($price, 0, 'Price cannot be negative');
        Assert::nullOrGreaterThan($costPrice, 0, 'Cost price must be greater than 0 if set');
        Assert::nullOrGreaterThanEq($salePrice, 0, 'Sale price cannot be negative');
    }

    /**
     * Check if the product is currently on sale.
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

    /**
     * Calculate profit margin as a percentage.
     *
     * @return float|null Margin percentage (null if cost price unknown)
     */
    public function marginPercent(): ?float
    {
        if ($this->costPrice === null) {
            return null;
        }

        return (($this->effectivePrice() - $this->costPrice) / $this->costPrice) * 100;
    }
}
