<?php

declare(strict_types=1);

namespace Database\Seeders\ProductSlice\Builders;

use Database\Seeders\ProductSlice\SeedProfile;
use UnexpectedValueException;

/**
 * Assigns the profile's product counts to product positions.
 */
final readonly class ProductRolesBuilder
{
    public function __construct(
        private SeedRandom $random,
    ) {}

    public function build(SeedProfile $profile): ProductRoles
    {
        $count = $profile->int('counts.products');
        $all = \range(0, $count - 1);
        $variationCounts = $this->exactly($count, $profile->intHistogram('products.variations_per_product'));
        $skus = $this->skuRoles($profile, $variationCounts);
        $sale = $this->saleRoles($profile, $variationCounts);
        $priced = SeedRandom::without($all, $sale['master'], $sale['variation']);
        $zeroPrice = $this->random->subset($priced, $profile->int('products.price_zero'));
        $delivery = $this->random->sample($all, $profile->int('products.free_delivery_values.Standard') + $profile->int('products.free_delivery_values.'));
        $standardCount = $profile->int('products.free_delivery_values.Standard');
        $gtin = $this->random->sample($all, $count - $profile->int('products.gtin_null_or_empty'));
        $firstDuplicate = $this->random->pick($all);
        $secondDuplicate = $this->random->pick(SeedRandom::without($all, [$firstDuplicate => true]));

        return new ProductRoles(
            variationCounts: $variationCounts,
            nullSku: $skus['null'],
            stockedSku: $skus['stocked'],
            whitespaceSku: $skus['whitespace'],
            masterSale: $sale['master'],
            variationSale: $sale['variation'],
            saleVariationsPerProduct: $sale['perProduct'],
            zeroPrice: $zeroPrice,
            overTwoDecimals: $this->random->subset(SeedRandom::without($priced, $zeroPrice), $profile->int('products.price_over_2dp')),
            zeroCost: $this->random->subset($all, $profile->int('products.cost_price_zero')),
            comparePrice: $this->random->subset($all, $profile->int('products.compare_price_positive')),
            vatExclusive: $this->random->subset($all, $profile->int('products.vat_exclusive')),
            vatRelief: $this->random->subset($all, $profile->int('products.vat_relief')),
            active: $this->random->subset($all, $profile->int('counts.products_active')),
            standardDelivery: SeedRandom::toSet(\array_slice($delivery, 0, $standardCount)),
            expressDelivery: SeedRandom::toSet(\array_slice($delivery, $standardCount)),
            bestBuy: $this->random->subset($all, $profile->int('products.custom_field_keys.best_buy.boolean')),
            dimensions: $this->random->subset($all, $profile->int('products.custom_field_keys.dimensions.array')),
            gtin: SeedRandom::toSet($gtin),
            whitespaceGtin: $gtin[0] ?? throw new UnexpectedValueException('Seed profile leaves no product with a GTIN'),
            imageCounts: $this->exactly($count, $profile->intHistogram('products.images_per_product')),
            categoryCounts: self::withSaleSlots(
                $this->exactly($count, $profile->intHistogram('products.categories_per_product')),
                $sale['master'] + $sale['variation'],
            ),
            duplicateTitle: [\min($firstDuplicate, $secondDuplicate), \max($firstDuplicate, $secondDuplicate)],
        );
    }

    /**
     * Products with variations take the null SKUs; the whitespace-padded SKU goes to a SKU-bearing product
     * without a stock item, so trimming never decides a stock match.
     *
     * @param list<int> $variationCounts
     *
     * @return array{null: array<int, true>, stocked: array<int, true>, whitespace: int}
     */
    private function skuRoles(SeedProfile $profile, array $variationCounts): array
    {
        $withVariations = \array_keys(\array_filter($variationCounts, static fn(int $variations): bool => $variations > 0));
        $nullSku = $this->random->subset($withVariations, $profile->int('products.sku_null'));
        $skuProducts = $this->random->shuffle(SeedRandom::without(\array_keys($variationCounts), $nullSku));
        $stockedCount = $profile->int('products.sku_with_stock_item');

        return [
            'null' => $nullSku,
            'stocked' => SeedRandom::toSet(\array_slice($skuProducts, 0, $stockedCount)),
            'whitespace' => $this->random->pick(\array_slice($skuProducts, $stockedCount)),
        ];
    }

    /**
     * Master-on-sale products have no variations; variation-on-sale products put their sale on variations only.
     *
     * @param list<int> $variationCounts
     *
     * @return array{master: array<int, true>, variation: array<int, true>, perProduct: int}
     */
    private function saleRoles(SeedProfile $profile, array $variationCounts): array
    {
        $masterCount = $profile->int('products.view_is_on_sale');
        $variationProductCount = $profile->int('products.view_has_any_sale') - $masterCount;
        $saleVariations = $profile->int('variations.view_is_on_sale');

        if ($variationProductCount <= 0 || $saleVariations % $variationProductCount !== 0) {
            throw new UnexpectedValueException('Seed profile sale counts do not split evenly over variation-on-sale products');
        }

        $perProduct = \intdiv($saleVariations, $variationProductCount);
        $withoutVariations = \array_keys(\array_filter($variationCounts, static fn(int $variations): bool => $variations === 0));
        $multiVariation = \array_keys(\array_filter($variationCounts, static fn(int $variations): bool => $variations >= $perProduct));

        return [
            'master' => $this->random->subset($withoutVariations, $masterCount),
            'variation' => $this->random->subset($multiVariation, $variationProductCount),
            'perProduct' => $perProduct,
        ];
    }

    /**
     * @param array<int, int> $histogram
     *
     * @return list<int>
     */
    private function exactly(int $count, array $histogram): array
    {
        $values = $this->random->shuffle(SeedRandom::expand($histogram));

        if (\count($values) !== $count) {
            throw new UnexpectedValueException('Seed profile distribution does not cover every product');
        }

        return $values;
    }

    /**
     * Sale products need at least one category slot, since one slot becomes the sale category.
     *
     * @param list<int> $categoryCounts
     * @param array<int, true> $saleProducts
     *
     * @return list<int>
     */
    private static function withSaleSlots(array $categoryCounts, array $saleProducts): array
    {
        $swapped = [];

        foreach (\array_keys($saleProducts) as $position) {
            if (($categoryCounts[$position] ?? 0) > 0) {
                continue;
            }

            [$donor, $slots] = self::donor($categoryCounts, $saleProducts + $swapped);
            $swapped[$position] = $slots;
            $swapped[$donor] = 0;
        }

        return \array_map(static fn(int $slots, int $position): int => $swapped[$position] ?? $slots, $categoryCounts, \array_keys($categoryCounts));
    }

    /**
     * The first product, outside `$excluded`, that has category slots to give.
     *
     * @param list<int> $categoryCounts
     * @param array<int, mixed> $excluded
     *
     * @return array{int, int} `[position, slots]`
     */
    private static function donor(array $categoryCounts, array $excluded): array
    {
        foreach ($categoryCounts as $candidate => $slots) {
            if ($slots > 0 && !isset($excluded[$candidate])) {
                return [$candidate, $slots];
            }
        }

        throw new UnexpectedValueException('Seed profile leaves no category slot for a sale product');
    }
}
