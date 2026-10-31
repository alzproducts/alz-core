<?php

declare(strict_types=1);

namespace Tests\Unit\Database\ProductSlice;

use Database\Seeders\ProductSlice\Builders\PopularityBuilder;
use Database\Seeders\ProductSlice\SliceTables;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The slice reproduces the committed profile's counts and structural distributions (AC3, plus AC1's counts in memory).
 */
#[CoversNothing]
final class ProductSliceShapeTest extends TestCase
{
    #[Test]
    public function table_counts_match_the_profile(): void
    {
        $slice = SliceFixture::rows();

        $this->assertSame([
            SliceTables::CUSTOM_FIELD_DEFINITIONS => 216,
            SliceTables::CATEGORIES => 96,
            SliceTables::FILTER_GROUPS => 5,
            SliceTables::PRODUCTS => 1000,
            SliceTables::PRODUCT_VARIATIONS => 3036,
            SliceTables::STOCK_ITEMS => 6375,
            SliceTables::STOCK_ITEM_EXTENDED_PROPERTIES => 0,
            SliceTables::SUPPLIERS => 116,
            SliceTables::STOCK_ITEM_SUPPLIERS => 3865,
            SliceTables::PRODUCT_EXTRA_DATA => 789,
            SliceTables::PRICE_PERIODS => 2657,
            SliceTables::COST_PRICE_CHANGES => 2388,
            SliceTables::PRODUCT_POPULARITY_SNAPSHOTS => 1000,
            SliceTables::SKU_POPULARITY_SNAPSHOTS => 3582,
        ], \array_map(\count(...), $slice->tables));
        $this->assertSame(SliceTables::INSERT_ORDER, $slice->tableNames());
    }

    #[Test]
    public function popularity_ranks_stay_within_the_config_max_rank(): void
    {
        $slice = SliceFixture::rows();

        foreach ([SliceTables::PRODUCT_POPULARITY_SNAPSHOTS, SliceTables::SKU_POPULARITY_SNAPSHOTS] as $table) {
            $ranks = SliceFixture::column($slice->rows($table), 'calculated_sort_order');

            $this->assertSame(1, \min($ranks), $table);
            $this->assertSame(PopularityBuilder::MAX_RANK, \max($ranks), $table);
        }
    }

    #[Test]
    public function variations_follow_the_long_tail(): void
    {
        $perProduct = \array_count_values(SliceFixture::column(SliceFixture::rows()->rows(SliceTables::PRODUCT_VARIATIONS), 'product_external_id'));

        $this->assertCount(186, $perProduct);
        $this->assertSame(120, \max($perProduct));
        $this->assertSame(3036, \array_sum($perProduct));
    }

    #[Test]
    public function category_ancestry_cte_yields_the_profile_row_count(): void
    {
        $categories = SliceFixture::rows()->rows(SliceTables::CATEGORIES);

        $this->assertCount(236, self::ancestorRows($categories));
        $this->assertSame(
            17,
            \count(\array_filter($categories, static fn(array $category): bool => ($category['custom_fields']['is_main_category'] ?? null) === true)),
        );
        $this->assertSame(
            [0 => 31, 1 => 58, 2 => 7],
            self::sortedCounts(\array_map(static fn(array $category): int => \count($category['parent_ids']), $categories)),
        );
    }

    #[Test]
    public function stock_items_and_supplier_links_match_the_profile(): void
    {
        $slice = SliceFixture::rows();
        $stockItems = $slice->rows(SliceTables::STOCK_ITEMS);
        $links = $slice->rows(SliceTables::STOCK_ITEM_SUPPLIERS);
        $itemNumbers = SliceFixture::column($stockItems, 'item_number');
        $productSkus = \array_filter(SliceFixture::column($slice->rows(SliceTables::PRODUCTS), 'sku'));
        $variationSkus = \array_filter(SliceFixture::column($slice->rows(SliceTables::PRODUCT_VARIATIONS), 'sku'));
        $linksPerItem = \array_count_values(SliceFixture::column($links, 'stock_item_id'));
        $defaults = \array_filter($links, static fn(array $link): bool => $link['is_default'] === true);

        $this->assertCount(\count($itemNumbers), \array_unique($itemNumbers), 'item_number must stay unique');
        $this->assertCount(814, \array_intersect($itemNumbers, $productSkus));
        $this->assertCount(2608, \array_intersect($itemNumbers, $variationSkus));
        $this->assertCount(3684, $linksPerItem);
        $this->assertSame([1 => 3554, 2 => 95, 3 => 26, 4 => 6, 6 => 2, 7 => 1], self::sortedCounts($linksPerItem));
        $this->assertCount(3683, $defaults);
        $this->assertCount(3683, \array_unique(SliceFixture::column($defaults, 'stock_item_id')), 'at most one default link per stock item');
        $this->assertCount(80, \array_unique(SliceFixture::column($links, 'supplier_id')));
        $this->assertSame(
            \count($links),
            \count(\array_unique(\array_map(static fn(array $link): string => $link['stock_item_id'] . '|' . $link['supplier_id'], $links))),
            'a stock item links each supplier once',
        );
    }

    #[Test]
    public function price_history_and_popularity_match_the_profile(): void
    {
        $slice = SliceFixture::rows();
        $periods = $slice->rows(SliceTables::PRICE_PERIODS);
        $openPeriods = \array_filter($periods, static fn(array $period): bool => $period['effective_to'] === null);
        $catalogSkus = \array_map(\trim(...), \array_filter([
            ...SliceFixture::column($slice->rows(SliceTables::PRODUCTS), 'sku'),
            ...SliceFixture::column($slice->rows(SliceTables::PRODUCT_VARIATIONS), 'sku'),
        ]));
        $snapshots = [...$slice->rows(SliceTables::PRODUCT_POPULARITY_SNAPSHOTS), ...$slice->rows(SliceTables::SKU_POPULARITY_SNAPSHOTS)];

        $this->assertSame([1 => 2455, 2 => 84, 3 => 10, 4 => 1], self::sortedCounts(\array_count_values(SliceFixture::column($periods, 'sku'))));
        $this->assertCount(2550, \array_unique(SliceFixture::column($openPeriods, 'sku')));
        $this->assertCount(2550, $openPeriods, 'exactly one open period per SKU');
        $this->assertSame([1 => 2346, 2 => 21], self::sortedCounts(\array_count_values(SliceFixture::column($slice->rows(SliceTables::COST_PRICE_CHANGES), 'sku'))));
        $this->assertCount(788, \array_intersect(SliceFixture::column($slice->rows(SliceTables::PRODUCT_EXTRA_DATA), 'sku'), $catalogSkus));
        $this->assertSame([1], \array_values(\array_unique(SliceFixture::column($snapshots, 'algorithm_version'))));
        $this->assertCount(1, \array_unique(SliceFixture::column($snapshots, 'snapshot_date')));
        $this->assertCount(3582, \array_intersect(SliceFixture::column($slice->rows(SliceTables::SKU_POPULARITY_SNAPSHOTS), 'live_sku'), $catalogSkus));
    }

    /**
     * The products view's `ancestors` CTE, evaluated as Postgres does: UNION ALL keeps every path's row.
     *
     * @param list<array<string, mixed>> $categories
     *
     * @return list<array{int, int}>
     */
    private static function ancestorRows(array $categories): array
    {
        $parentsById = [];

        foreach ($categories as $category) {
            $parentsById[$category['external_id']] = $category['parent_ids'];
        }

        $rows = [];
        $frontier = \array_map(static fn(int $id): array => [$id, $id], \array_keys($parentsById));

        while ($frontier !== []) {
            $rows = [...$rows, ...$frontier];

            if (\count($rows) > 10_000) {
                throw new RuntimeException('Category ancestry does not terminate');
            }

            $next = [];

            foreach ($frontier as [$categoryId, $ancestorId]) {
                foreach ($parentsById[$ancestorId] ?? [] as $parentId) {
                    $next[] = [$categoryId, (int) $parentId];
                }
            }

            $frontier = $next;
        }

        return $rows;
    }

    /**
     * @param array<int|string, int> $values
     *
     * @return array<int, int> value => how many times it occurs, sorted by value
     */
    private static function sortedCounts(array $values): array
    {
        $counts = \array_count_values(\array_values($values));
        \ksort($counts);

        return $counts;
    }
}
