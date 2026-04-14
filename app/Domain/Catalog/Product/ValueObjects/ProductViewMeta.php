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

    public bool $canEditCostPrice;

    /**
     * @param list<ProductVariationView>|null $variations Variations (null = not loaded)
     * @param ProductSupplier|null $defaultSupplier Product-level default supplier
     */
    public function __construct(?array $variations, ?ProductSupplier $defaultSupplier)
    {
        $this->canEditRrp = self::resolveCanEditRrp($variations);
        $this->canEditCostPrice = self::resolveCanEditCostPrice($variations, $defaultSupplier);
    }

    /**
     * @return array{can_edit_rrp: bool, can_edit_cost_price: bool}
     */
    public function toArray(): array
    {
        return [
            'can_edit_rrp' => $this->canEditRrp,
            'can_edit_cost_price' => $this->canEditCostPrice,
        ];
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
     * Cost price can be edited when a single consistent supplier exists.
     *
     * Without variations: requires the product-level default supplier.
     * With variations: requires every variation to have a default supplier with the same name.
     *
     * @param list<ProductVariationView>|null $variations
     */
    private static function resolveCanEditCostPrice(?array $variations, ?ProductSupplier $defaultSupplier): bool
    {
        if ($variations === null || $variations === []) {
            return $defaultSupplier !== null;
        }

        return ProductVariationView::commonDefaultSupplier($variations) !== null;
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
