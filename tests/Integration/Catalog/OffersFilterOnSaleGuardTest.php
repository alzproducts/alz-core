<?php

declare(strict_types=1);

namespace Tests\Integration\Catalog;

use Illuminate\Support\Facades\DB;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guard test: `catalog.products_with_changed_offers_filters` must derive "On Sale"
 * (filter slot 14) from "master SKU or any variation SKU on sale".
 *
 * Runs against the seeded catalog (or opt-in synced prod data) plus an
 * inheritance-only fixture (variation with no sale price of its own, on sale via
 * its product's) inserted in a rolled-back transaction.
 */
#[CoversNothing]
#[Group('integration')]
final class OffersFilterOnSaleGuardTest extends TestCase
{
    private const int PRODUCT_EXTERNAL_ID = 2147483011;

    private const int VARIATION_EXTERNAL_ID = 2147483012;

    private const string HAS_ANY_SALE = <<<'SQL'
        (
            (p.sale_price IS NOT NULL AND p.sale_price > 0 AND p.sale_price < p.price)
            OR EXISTS (
                SELECT 1
                FROM catalog.product_variations_view pv
                WHERE pv.product_id = p.id
                  AND pv.is_on_sale
            )
        )
        SQL;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // intentional: no DB trait — under --parallel it swaps to a per-worker DB without synced data
        DB::beginTransaction();

        $saleCategoryId = \config('shopwired.sale_category_id');
        self::assertIsInt($saleCategoryId, 'shopwired.sale_category_id must be configured');
        self::assertGreaterThan(0, $saleCategoryId, 'shopwired.sale_category_id must be a positive int');

        $this->insertInheritanceOnlyFixture();
    }

    #[Override]
    protected function tearDown(): void
    {
        DB::rollBack();

        parent::tearDown();
    }

    #[Test]
    public function every_view_row_carries_on_sale_exactly_when_product_has_any_sale(): void
    {
        $mismatched = DB::select(
            'SELECT o.product_id FROM catalog.products_with_changed_offers_filters o '
            . 'JOIN shopwired.products p ON p.external_id = o.product_id '
            . "WHERE (o.desired_filter_values @> '[\"On Sale\"]'::jsonb) IS DISTINCT FROM " . self::HAS_ANY_SALE
            . ' ORDER BY o.product_id LIMIT 20',
        );

        self::assertSame([], \array_map(static fn(object $row): int => (int) $row->product_id, $mismatched));
    }

    #[Test]
    public function every_product_whose_on_sale_filter_disagrees_with_has_any_sale_is_in_the_view(): void
    {
        $missing = DB::select(
            'SELECT p.external_id FROM shopwired.products p '
            . 'WHERE EXISTS ('
            . "    SELECT 1 FROM jsonb_array_elements_text(COALESCE(p.filters->'14', '[]'::jsonb)) AS slot(value)"
            . "    WHERE LOWER(slot.value) = 'on sale'"
            . ') IS DISTINCT FROM ' . self::HAS_ANY_SALE
            . ' AND NOT EXISTS ('
            . '    SELECT 1 FROM catalog.products_with_changed_offers_filters o WHERE o.product_id = p.external_id'
            . ') ORDER BY p.external_id LIMIT 20',
        );

        self::assertSame([], \array_map(static fn(object $row): int => (int) $row->external_id, $missing));
    }

    #[Test]
    public function inheritance_only_fixture_is_flagged_with_on_sale(): void
    {
        $row = DB::selectOne(
            'SELECT o.desired_filter_values FROM catalog.products_with_changed_offers_filters o '
            . 'JOIN shopwired.products p ON p.external_id = o.product_id '
            . 'WHERE p.external_id = ?',
            [self::PRODUCT_EXTERNAL_ID],
        );

        self::assertIsObject($row, 'Inheritance-only fixture must be flagged by the Offers view');
        self::assertIsString($row->desired_filter_values);
        self::assertSame(['Free Delivery', 'On Sale'], \json_decode($row->desired_filter_values, true));
    }

    private function insertInheritanceOnlyFixture(): void
    {
        self::assertFalse(
            DB::table('shopwired.products')->where('external_id', self::PRODUCT_EXTERNAL_ID)->exists(),
            'Sentinel product external_id already exists',
        );
        self::assertFalse(
            DB::table('shopwired.product_variations')->where('external_id', self::VARIATION_EXTERNAL_ID)->exists(),
            'Sentinel variation external_id already exists',
        );

        $now = \now();
        $productId = DB::table('shopwired.products')->insertGetId([
            'external_id' => self::PRODUCT_EXTERNAL_ID,
            'title' => 'Guard fixture: inheritance-only sale',
            'slug' => 'guard-offers-on-sale-' . self::PRODUCT_EXTERNAL_ID,
            'url' => '/guard-offers-on-sale-' . self::PRODUCT_EXTERNAL_ID,
            'price' => 10,
            'sale_price' => 12,
            'is_active' => true,
            'vat_exclusive' => false,
            'category_ids' => \json_encode([]),
            'images' => \json_encode([]),
            'custom_fields' => \json_encode((object) []),
            // A sibling value in slot 14, so the fixture also checks siblings survive the rebuild
            'filters' => \json_encode(['14' => ['Free Delivery']]),
            'shopwired_created_at' => $now,
            'shopwired_updated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('shopwired.product_variations')->insert([
            'product_id' => $productId,
            'product_external_id' => self::PRODUCT_EXTERNAL_ID,
            'external_id' => self::VARIATION_EXTERNAL_ID,
            'price' => 15,
            'sale_price' => null,
            'stock' => 0,
            'options' => \json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
