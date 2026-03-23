<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Contracts;

/**
 * Common interface for purchasable catalog items.
 *
 * Represents the essential attributes shared by both Products and ProductVariations:
 * pricing, identification, and availability. Enables unified operations like SKU lookups,
 * price comparisons, and stock checks across both entity types.
 *
 * Use cases:
 * - `getBasicProduct()` returning either Product or Variation
 * - Unified pricing displays in carts/listings
 * - Stock availability checks
 *
 * TODO: Expand to include ProductVariation. Currently blocked because ShopWired variations
 * can have nullable prices (null = inherit parent price, 0.00 = removed from sale).
 * This creates semantic issues with isOnSale() and effectivePrice() requiring parent context.
 * See: .ai/docs/known-issues.md "BasicProductInterface and ProductVariation"
 */
interface BasicProductInterface
{
    /**
     * Get the SKU (Stock Keeping Unit).
     *
     * May be null if not assigned (some products/variations don't have SKUs).
     */
    public function sku(): ?string;

    /**
     * Get the regular selling price.
     */
    public function price(): float;

    /**
     * Get the cost/wholesale price.
     *
     * Used for margin calculations. Null if not tracked.
     */
    public function costPrice(): ?float;

    /**
     * Get the sale/discounted price.
     *
     * Null means item is not on sale.
     */
    public function salePrice(): ?float;

    /**
     * Get the weight.
     *
     * Unit depends on store configuration. Null if not applicable.
     */
    public function weight(): ?float;

    /**
     * Check if this item is currently on sale.
     *
     * True when salePrice is set, greater than zero, AND less than regular price.
     * A salePrice of 0 means "no sale" in ShopWired.
     */
    public function isOnSale(): bool;

    /**
     * Get the effective selling price.
     *
     * Returns salePrice if on sale, otherwise regular price.
     */
    public function effectivePrice(): float;

    /**
     * Check if this item is in stock.
     *
     * For products with variations, this checks total stock across all variations.
     * For standalone products and variations, this checks direct stock.
     */
    public function isInStock(): bool;

    /**
     * Get the stock level for this item.
     *
     * The meaning differs by item type:
     * - **Product (parent)**: Returns total stock across all variations if the product
     *   has variations, otherwise returns the master product's stock. This represents
     *   "how many units of this product can be sold in total".
     * - **ProductVariation**: Returns this specific variation's stock quantity.
     *   This represents "how many units of this exact variant can be sold".
     *
     * Use this when you need a single stock number regardless of item type.
     * For more granular control, cast to the concrete type.
     */
    public function getStockLevel(): int;
}
