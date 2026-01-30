<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Resolved prices for a product variation.
 *
 * Contains final price values after resolving null values to parent defaults.
 * This VO guarantees non-null prices, simplifying downstream logic.
 *
 * @see VariationPriceResolver for resolution logic
 */
final readonly class ResolvedVariationPrices
{
    /**
     * @param float $price Selling price (resolved from variation or parent)
     * @param float $costPrice Cost/wholesale price (resolved from variation or parent, 0.0 if both null)
     * @param float|null $salePrice Discounted price (null = no sale)
     */
    public function __construct(
        public float $price,
        public float $costPrice,
        public ?float $salePrice,
    ) {
        Assert::greaterThanEq($price, 0, 'Price cannot be negative');
        Assert::greaterThanEq($costPrice, 0, 'Cost price cannot be negative');
        Assert::nullOrGreaterThanEq($salePrice, 0, 'Sale price cannot be negative');
    }

    /**
     * Check if the product is currently on sale.
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
        // Inline the null check so PHPStan can narrow the type
        if ($this->salePrice !== null && $this->salePrice < $this->price) {
            return $this->salePrice;
        }

        return $this->price;
    }

    /**
     * Calculate profit margin as a percentage.
     *
     * @return float|null Margin percentage (null if cost price is 0 to avoid division issues)
     */
    public function marginPercent(): ?float
    {
        if ($this->costPrice === 0.0) {
            return null;
        }

        return (($this->effectivePrice() - $this->costPrice) / $this->costPrice) * 100;
    }
}
