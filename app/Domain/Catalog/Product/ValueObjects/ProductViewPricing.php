<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use App\Domain\Shared\Money\ValueObjects\Money;

/**
 * Aggregated pricing for a {@see ProductView}.
 *
 * Reconciles master-level pricing with variation-level data: falls back to the
 * common-price and minimum-price strategies when the master stores a zero or
 * missing value (typical of variant-only products).
 */
final readonly class ProductViewPricing
{
    public function __construct(
        public Money $price,
        public Money $effectivePrice,
        public ?Money $costPrice,
        public ?float $profitMargin,
    ) {}

    /** @param list<ProductVariationView>|null $variations */
    public static function aggregate(MasterPricing $master, ?array $variations): self
    {
        // Treat empty variations the same as null so downstream helpers have a single no-variations path.
        $variations = $variations === [] ? null : $variations;

        $commonEffective = $variations !== null && $master->effectivePrice->isZero()
            ? ProductVariationView::commonEffectivePrice($variations) : null;
        $commonCost = $variations !== null && $master->costPrice === null
            ? ProductVariationView::commonCostPrice($variations) : null;

        return new self(
            price: self::resolvePrice($master->price, $variations),
            effectivePrice: $commonEffective ?? self::resolveEffectiveFallback($master->effectivePrice, $variations),
            costPrice: $commonCost ?? $master->costPrice,
            profitMargin: self::recomputeMargin($commonEffective, $commonCost) ?? $master->profitMargin,
        );
    }

    /**
     * True when all sellable SKUs share the same selling price. Falls back to the
     * first variation's price when the resolved master price is still zero so the
     * comparison has a real reference.
     *
     * @param list<ProductVariationView>|null $variations
     */
    public static function hasSingleSellingPrice(Money $price, ?array $variations): bool
    {
        if ($variations === null || $variations === []) {
            return true;
        }

        $reference = $price->isZero() ? $variations[0]->price : $price;

        return \array_all(
            $variations,
            static fn(ProductVariationView $v): bool => $v->price->amountEquals($reference),
        );
    }

    /** @param list<ProductVariationView>|null $variations */
    private static function resolvePrice(Money $masterPrice, ?array $variations): Money
    {
        if ($variations === null || ! $masterPrice->isZero()) {
            return $masterPrice;
        }

        return ProductVariationView::commonPrice($variations)
            ?? ProductVariationView::minPrice($variations)
            ?? $masterPrice;
    }

    /** @param list<ProductVariationView>|null $variations */
    private static function resolveEffectiveFallback(Money $masterEffective, ?array $variations): Money
    {
        if ($variations === null || ! $masterEffective->isZero()) {
            return $masterEffective;
        }

        return ProductVariationView::minEffectivePrice($variations) ?? $masterEffective;
    }

    private static function recomputeMargin(?Money $commonEffective, ?Money $commonCost): ?float
    {
        if ($commonEffective === null || $commonCost === null || $commonEffective->isZero()) {
            return null;
        }

        return \round(($commonEffective->toNet() - $commonCost->toNet()) / $commonEffective->toNet() * 100, 2);
    }
}
