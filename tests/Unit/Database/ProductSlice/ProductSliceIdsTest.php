<?php

declare(strict_types=1);

namespace Tests\Unit\Database\ProductSlice;

use Database\Seeders\ProductSlice\ProfileSliceGenerator;
use Database\Seeders\ProductSlice\SeedIds;
use Database\Seeders\ProductSlice\SliceRows;
use Database\Seeders\ProductSlice\SliceTables;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Seeded ShopWired IDs stay inside the reserved range, clear of the guard tests' fixture IDs (AC9),
 * and the sale category matches every has-any-sale product and nothing else.
 */
#[CoversNothing]
final class ProductSliceIdsTest extends TestCase
{
    private const array GUARD_FIXTURE_IDS = [2_147_483_001, 2_147_483_002, 2_147_483_011, 2_147_483_012];

    #[Test]
    public function every_shopwired_id_sits_in_the_reserved_range_except_the_pinned_filter_groups(): void
    {
        $outside = \array_filter(self::shopwiredIds(SliceFixture::rows()), static fn(int $id): bool => !SeedIds::inReservedRange($id));
        $pinnedFilterGroups = [];

        foreach (SliceFixture::rows()->rows(SliceTables::FILTER_GROUPS) as $group) {
            if (!SeedIds::inReservedRange($group['external_id'])) {
                $pinnedFilterGroups[$group['external_id']] = $group['option_no'];
            }
        }

        $this->assertEqualsCanonicalizing(\array_keys(SeedIds::PINNED_FILTER_GROUPS), \array_values(\array_unique($outside)));
        $this->assertEquals(SeedIds::PINNED_FILTER_GROUPS, $pinnedFilterGroups);
        $this->assertLessThan(\min(self::GUARD_FIXTURE_IDS), SeedIds::RANGE_MAX);
        $this->assertEmpty(\array_intersect(self::shopwiredIds(SliceFixture::rows()), self::GUARD_FIXTURE_IDS));
    }

    #[Test]
    public function external_ids_are_unique_per_table(): void
    {
        $slice = SliceFixture::rows();

        foreach ([SliceTables::CUSTOM_FIELD_DEFINITIONS, SliceTables::CATEGORIES, SliceTables::FILTER_GROUPS, SliceTables::PRODUCTS, SliceTables::PRODUCT_VARIATIONS] as $table) {
            $ids = SliceFixture::column($slice->rows($table), 'external_id');
            $this->assertCount(\count($ids), \array_unique($ids), $table);
        }
    }

    #[Test]
    public function product_and_variation_skus_never_collide(): void
    {
        $slice = SliceFixture::rows();
        $productSkus = \array_map(\trim(...), \array_filter(SliceFixture::column($slice->rows(SliceTables::PRODUCTS), 'sku')));
        $variationSkus = \array_filter(SliceFixture::column($slice->rows(SliceTables::PRODUCT_VARIATIONS), 'sku'));

        $this->assertEmpty(\array_intersect($productSkus, $variationSkus));
        $this->assertCount(\count($productSkus), \array_unique($productSkus));
        $this->assertCount(\count($variationSkus), \array_unique($variationSkus));
    }

    #[Test]
    public function the_sale_category_takes_the_configured_slot_without_a_collision(): void
    {
        $categories = SliceFixture::rows()->rows(SliceTables::CATEGORIES);
        $inSlots = \array_filter(
            SliceFixture::column($categories, 'external_id'),
            static fn(int $id): bool => $id >= SeedIds::SINGLETON_SLOT_MIN && $id <= SeedIds::SINGLETON_SLOT_MAX,
        );

        $this->assertSame([2_000_000_001], \array_values($inSlots));
        $this->assertNotContains(2_000_000_001, \array_merge(...SliceFixture::column($categories, 'parent_ids')), 'the sale category is never a parent');
    }

    #[Test]
    public function sale_category_membership_matches_has_any_sale(): void
    {
        $slice = SliceFixture::rows();
        $hasAnySale = self::hasAnySale($slice);
        $inSaleCategory = [];

        foreach ($slice->rows(SliceTables::PRODUCTS) as $product) {
            $inSaleCategory[$product['external_id']] = \in_array(SliceFixture::SALE_CATEGORY_ID, $product['category_ids'], true);
        }

        $this->assertSame($hasAnySale, $inSaleCategory);
        $this->assertCount(11, \array_filter($hasAnySale));
        $this->assertNotEmpty(\array_filter($hasAnySale, static fn(bool $sale): bool => !$sale));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function outOfSlotSaleCategoryIds(): iterable
    {
        yield 'unset config' => [0];
        yield 'below the slots' => [1_999_999_999];
        yield 'first non-slot category ID' => [SeedIds::CATEGORY_BASE];
        yield 'guard fixture ID' => [2_147_483_001];
    }

    #[Test]
    #[DataProvider('outOfSlotSaleCategoryIds')]
    public function a_sale_category_id_outside_the_singleton_slots_is_refused(int $saleCategoryId): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProfileSliceGenerator()->generate(SliceFixture::profile(), SliceFixture::SEED, $saleCategoryId);
    }

    /**
     * @return list<int>
     */
    private static function shopwiredIds(SliceRows $slice): array
    {
        $ids = [];

        foreach ([SliceTables::CUSTOM_FIELD_DEFINITIONS, SliceTables::CATEGORIES, SliceTables::FILTER_GROUPS, SliceTables::PRODUCTS, SliceTables::PRODUCT_VARIATIONS] as $table) {
            \array_push($ids, ...SliceFixture::column($slice->rows($table), 'external_id'));
        }

        foreach ($slice->rows(SliceTables::CATEGORIES) as $category) {
            \array_push($ids, ...\array_map(\intval(...), $category['parent_ids']));
        }

        foreach ($slice->rows(SliceTables::PRODUCTS) as $product) {
            \array_push($ids, ...$product['category_ids'], ...\array_column($product['images'], 'id'));
        }

        foreach ($slice->rows(SliceTables::PRODUCT_VARIATIONS) as $variation) {
            \array_push($ids, $variation['product_external_id'], ...\array_column($variation['options'], 'option_id'), ...\array_column($variation['options'], 'value_id'));
        }

        return $ids;
    }

    /**
     * `catalog.products_view`'s has_any_sale: the master is on sale, or any variation is, with a variation's
     * missing price or sale price falling back to the product's.
     *
     * @return array<int, bool>
     */
    private static function hasAnySale(SliceRows $slice): array
    {
        $onSale = static fn(?float $sale, ?float $price): bool => $sale !== null && $price !== null && $sale > 0 && $sale < $price;
        $products = [];
        $hasAnySale = [];

        foreach ($slice->rows(SliceTables::PRODUCTS) as $product) {
            $products[$product['external_id']] = $product;
            $hasAnySale[$product['external_id']] = $onSale($product['sale_price'], $product['price']);
        }

        foreach ($slice->rows(SliceTables::PRODUCT_VARIATIONS) as $variation) {
            $product = $products[$variation['product_external_id']];
            $variationOnSale = $onSale($variation['sale_price'] ?? $product['sale_price'], $variation['price'] ?? $product['price']);
            $hasAnySale[$variation['product_external_id']] = $hasAnySale[$variation['product_external_id']] || $variationOnSale;
        }

        return $hasAnySale;
    }
}
