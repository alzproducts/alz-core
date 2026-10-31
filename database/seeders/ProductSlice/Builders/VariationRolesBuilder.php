<?php

declare(strict_types=1);

namespace Database\Seeders\ProductSlice\Builders;

use Database\Seeders\ProductSlice\SeedProfile;
use UnexpectedValueException;

final readonly class VariationRolesBuilder
{
    public function __construct(
        private SeedRandom $random,
    ) {}

    public function build(SeedProfile $profile, ProductRoles $products): VariationRoles
    {
        $total = \array_sum($products->variationCounts);

        if ($total !== $profile->int('counts.variations')) {
            throw new UnexpectedValueException('Seed profile variations_per_product does not sum to the variation count');
        }

        $offsets = self::offsets($products->variationCounts);
        $all = $total > 0 ? \range(0, $total - 1) : [];
        $onSale = self::saleVariations($products, $offsets);
        $nullSku = $this->random->subset($all, $profile->int('variations.sku_null'));

        return new VariationRoles(
            offsets: $offsets,
            nullSku: $nullSku,
            stocked: $this->random->subset(SeedRandom::without($all, $nullSku), $profile->int('variations.with_stock_item')),
            zeroPrice: $this->random->subset(SeedRandom::without($all, $onSale), $profile->int('variations.price_zero')),
            zeroCost: $this->random->subset($all, $profile->int('variations.cost_price_zero')),
            gtin: $this->random->subset($all, $total - $profile->int('variations.gtin_null_or_empty')),
            onSale: $onSale,
        );
    }

    /**
     * @param list<int> $variationCounts
     *
     * @return list<int>
     */
    private static function offsets(array $variationCounts): array
    {
        $offsets = [];
        $next = 0;

        foreach ($variationCounts as $count) {
            $offsets[] = $next;
            $next += $count;
        }

        return $offsets;
    }

    /**
     * The first variations of each variation-on-sale product.
     *
     * @param list<int> $offsets
     *
     * @return array<int, true>
     */
    private static function saleVariations(ProductRoles $products, array $offsets): array
    {
        $onSale = [];

        foreach (\array_keys($products->variationSale) as $position) {
            $first = $offsets[$position] ?? throw new UnexpectedValueException('Variation-on-sale product has no variations');
            $onSale += SeedRandom::toSet(\range($first, $first + $products->saleVariationsPerProduct - 1));
        }

        return $onSale;
    }
}
