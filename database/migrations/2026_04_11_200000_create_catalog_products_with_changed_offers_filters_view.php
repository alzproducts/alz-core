<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Merge-preserving view that flags products whose ShopWired "Offers → On Sale"
 * filter (optionNo 14) has drifted from the canonical pricing rule.
 *
 * The rule (matches `Product::isSaleActive()`):
 *   sale_price IS NOT NULL AND sale_price > 0 AND sale_price < price
 *
 * Variant-level: a product is on-sale if EITHER the parent row OR any variant
 * row is on-sale. Variants with `price = NULL` inherit the parent price via
 * `COALESCE(v.price, p.price)`.
 *
 * `filters->'14'` is a multi-value slot shared with admin-maintained siblings
 * (e.g. "Free Delivery"). The view rebuilds the desired array by stripping any
 * casing of "on sale" from the current slot and re-appending canonical
 * "On Sale" when the product is on-sale. Siblings are preserved.
 *
 * Diff is order-insensitive: both sides of `IS DISTINCT FROM` are sorted via
 * `jsonb_agg(... ORDER BY value)` so a storefront-reordered slot doesn't
 * produce a spurious drift row.
 */
return new class extends Migration {
    public function up(): void
    {
        // 14 = FilterGroupOptionNo::Offers (keep in sync with FilterGroupOptionNo.php)
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW catalog.products_with_changed_offers_filters AS
            WITH product_sale_state AS (
                SELECT
                    p.external_id AS product_id,
                    COALESCE(p.filters->'14', '[]'::jsonb) AS slot14,
                    (
                        (p.sale_price IS NOT NULL AND p.sale_price > 0 AND p.sale_price < p.price)
                        OR EXISTS (
                            SELECT 1
                            FROM shopwired.product_variations v
                            WHERE v.product_external_id = p.external_id
                              AND v.sale_price IS NOT NULL
                              AND v.sale_price > 0
                              AND v.sale_price < COALESCE(v.price, p.price)
                        )
                    ) AS is_on_sale
                FROM shopwired.products p
            ),
            diff AS (
                SELECT
                    pss.product_id,
                    COALESCE(
                        (
                            SELECT jsonb_agg(value ORDER BY value)
                            FROM jsonb_array_elements_text(pss.slot14) AS value
                        ),
                        '[]'::jsonb
                    ) AS current_sorted,
                    COALESCE(
                        (
                            SELECT jsonb_agg(value ORDER BY value)
                            FROM (
                                SELECT value
                                FROM jsonb_array_elements_text(pss.slot14) AS value
                                WHERE LOWER(value) <> 'on sale'
                                UNION ALL
                                SELECT 'On Sale' WHERE pss.is_on_sale
                            ) AS combined(value)
                        ),
                        '[]'::jsonb
                    ) AS desired_filter_values
                FROM product_sale_state pss
            )
            SELECT product_id, desired_filter_values
            FROM diff
            WHERE current_sorted IS DISTINCT FROM desired_filter_values
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS catalog.products_with_changed_offers_filters');
    }
};
