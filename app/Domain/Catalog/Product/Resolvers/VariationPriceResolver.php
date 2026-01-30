<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Resolvers;

use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Catalog\Product\ValueObjects\ResolvedVariationPrices;

/**
 * Resolves variation prices by inheriting from parent when null.
 *
 * **Resolution Rules:**
 * - `null` → Inherit from parent (variation doesn't override)
 * - `0.00` → Keep as zero (explicitly set, e.g., "temporarily removed from sale")
 *
 * This distinction is critical: a £0.00 price is intentional (perhaps a free
 * sample or withdrawn item), while null means "use the parent's price".
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
        // Price: variation → parent (parent always has a price)
        $price = $variation->price ?? $parentPrice;

        // Cost price: variation → parent (null = unknown, 0.00 not valid for cost)
        $costPrice = $variation->costPrice ?? $parentCostPrice;

        // Sale price: variation → parent (both can be null = no sale)
        $salePrice = $variation->salePrice ?? $parentSalePrice;

        return new ResolvedVariationPrices(
            price: $price,
            costPrice: $costPrice,
            salePrice: $salePrice,
        );
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
