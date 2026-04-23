<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Resolvers;

use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Catalog\Product\ValueObjects\ResolvedVariationPrices;

/**
 * Resolves variation prices by inheriting from parent when null.
 *
 * **Resolution Rules for price/salePrice:**
 * - `null` → Inherit from parent (variation doesn't override)
 * - `0.00` → Keep as zero (explicitly set, e.g., "temporarily removed from sale")
 *
 * **Resolution Rules for costPrice (ShopWired-specific):**
 * - Variation `-1.0` → Inherit from parent (ShopWired's "not set" sentinel)
 * - Variation `0.00` → Treat as null/unknown (invalid cost price)
 * - Variation `> 0.00` → Valid variation cost price
 * - Parent `-1.0` or `0.00` → Normalized to null before inheritance
 *   (ShopWired returns -1 for unset parent cost; 0 is never a valid cost)
 *
 * This distinction is critical: a £0.00 selling price is intentional (perhaps
 * a free sample or withdrawn item), while null means "use the parent's price".
 * For cost prices, neither -1 nor 0 are valid business values.
 *
 * **Usage:**
 * ```php
 * $resolver = new VariationPriceResolver();
 * $prices = $resolver->resolve($variation, $product->price, $product->costPrice, $product->salePrice);
 * ```
 *
 * @template-pattern Domain Service
 */
final readonly class VariationPriceResolver
{
    /**
     * Resolve variation prices, falling back to parent values when null.
     *
     * @param ProductVariation $variation The variation to resolve prices for
     * @param float $parentPrice Parent product's selling price (required fallback)
     * @param float|null $parentCostPrice Parent product's cost price
     * @param float|null $parentSalePrice Parent product's sale price
     */
    public function resolve(
        ProductVariation $variation,
        float $parentPrice,
        ?float $parentCostPrice,
        ?float $parentSalePrice,
    ): ResolvedVariationPrices {
        return new ResolvedVariationPrices(
            price: $variation->price ?? $parentPrice,
            costPrice: $this->resolveCostPrice($variation->costPrice, $parentCostPrice),
            salePrice: $variation->salePrice ?? $parentSalePrice,
        );
    }

    /**
     * Resolve cost price with ShopWired sentinel value handling.
     *
     * @param float|null $variationCost Variation's cost price from ShopWired
     * @param float|null $parentCost Parent product's cost price
     *
     * @return float|null Resolved cost price (null = unknown)
     */
    private function resolveCostPrice(?float $variationCost, ?float $parentCost): ?float
    {
        // Normalize parent: ShopWired may emit -1.0 or 0.0 for "unset/invalid"
        $normalizedParent = $this->normalizeSentinel($parentCost);

        // null or -1.0 = inherit from (normalized) parent
        if ($variationCost === null || $variationCost === -1.0) {
            return $normalizedParent;
        }

        // 0.00 = treat as unknown (not a valid cost price)
        if ($variationCost === 0.0) {
            return null;
        }

        // > 0.00 = valid variation cost price
        return $variationCost;
    }

    private function normalizeSentinel(?float $cost): ?float
    {
        if ($cost === null || $cost === -1.0 || $cost === 0.0) {
            return null;
        }

        return $cost;
    }

    /**
     * Resolve prices from a variation using parent Product.
     *
     * Convenience method when you have the full Product object.
     *
     * @param ProductVariation $variation The variation to resolve prices for
     * @param Product $product Parent product
     */
    public function resolveFromProduct(
        ProductVariation $variation,
        Product $product,
    ): ResolvedVariationPrices {
        return $this->resolve(
            $variation,
            $product->price,
            $product->costPrice,
            $product->salePrice,
        );
    }
}
