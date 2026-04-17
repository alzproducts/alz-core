<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Read-side stock totals for a product or variation view.
 *
 * Exposes two concepts:
 * - availableStock: what's sellable right now (what ShopWired displays; matches
 *   the value pushed by the stock sync — Linnworks `Level_LessOrderBook`).
 * - physicalStock: on-hand quantity before order-book allocation.
 *
 * For a parent ProductView with variations, both totals sum across variations
 * via fromParentAndVariants() — parent SKUs don't hold their own stock when
 * variations exist.
 */
final readonly class Stock
{
    public function __construct(
        public int $availableStock,
        public int $physicalStock,
    ) {
        Assert::greaterThanEq($availableStock, 0, 'availableStock cannot be negative');
        Assert::greaterThanEq($physicalStock, 0, 'physicalStock cannot be negative');
    }

    /**
     * Build a parent product's Stock from its own values or by summing variations.
     *
     * When variations are present (non-null, non-empty), the parent's totals are
     * the sum of variation totals — parent SKUs don't carry their own stock in
     * that case. Otherwise the parent's own raw values are used.
     *
     * @param list<ProductVariationView>|null $variations
     */
    public static function fromParentAndVariants(
        int $parentAvailable,
        int $parentPhysical,
        ?array $variations,
    ): self {
        if ($variations === null || $variations === []) {
            return new self($parentAvailable, $parentPhysical);
        }

        $available = 0;
        $physical = 0;
        foreach ($variations as $variation) {
            $available += $variation->stockLevel->availableStock;
            $physical += $variation->stockLevel->physicalStock;
        }

        return new self($available, $physical);
    }
}
