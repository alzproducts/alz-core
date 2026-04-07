<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

/**
 * Computed meta flags for the product detail API response.
 *
 * Self-constructs all flags from the raw inputs passed to its constructor.
 * Each flag has a dedicated `resolve*()` method to keep logic isolated.
 */
final readonly class ProductViewMeta
{
    public bool $canEditRrp;

    /**
     * @param list<ProductVariationView>|null $variations Variations (null = not loaded)
     */
    public function __construct(?array $variations)
    {
        $this->canEditRrp = self::resolveCanEditRrp($variations);
    }

    /**
     * RRP can be edited when variations are absent or all share the same base price.
     *
     * Uses base price (not effectivePrice) — RRP is permanent, not tied to active sales.
     *
     * @param list<ProductVariationView>|null $variations
     */
    private static function resolveCanEditRrp(?array $variations): bool
    {
        return $variations === null
            || $variations === []
            || self::variationsHaveSameSellingPrice($variations);
    }

    /**
     * @param list<ProductVariationView> $variations Non-empty list
     */
    private static function variationsHaveSameSellingPrice(array $variations): bool
    {
        $uniquePrices = \array_unique(\array_map(
            static fn(ProductVariationView $v): float => $v->price->toGross(),
            $variations,
        ));

        return \count($uniquePrices) === 1;
    }
}
