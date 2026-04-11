<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * View that flags products whose ShopWired "Shipping Options" filter
 * (optionNo 25) has drifted from the canonical stock-availability rule.
 *
 * Mapping:
 *   parent stock > 0, OR any variation stock > 0  → ["Next Day Delivery Available"]
 *   otherwise (null or ≤ 0 on parent, no in-stock variation)  → []  (slot cleared)
 *
 * Source columns:
 *   shopwired.products.stock           — nullable (NULL for products with variations)
 *   shopwired.product_variations.stock — not-null (variation-level stock)
 *
 * Both columns are Linnworks-mirrored copies (written by SyncFullStockToShopwiredJob /
 * SyncDeltaStockToShopwiredJob). When investigating stale data, trace upstream to Linnworks.
 *
 * Slot 25 is a dedicated group — no admin-maintained siblings. No
 * merge-preserving strip/re-append logic is needed.
 *
 * Diff is order-insensitive on the current-slot side: `jsonb_agg(... ORDER BY value)`
 * sorts the existing `filters->'25'` values before comparison, so a storefront-reordered
 * slot doesn't produce a spurious drift row.
 */
return new class extends Migration {
    public function up(): void
    {
        // 25 = FilterGroupOptionNo::ShippingOptions (keep in sync with FilterGroupOptionNo.php)
        // ShippingOptionsFilterValue::NextDayDeliveryAvailable = 'Next Day Delivery Available'
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW catalog.products_with_changed_shipping_options_filters AS
            WITH product_stock_state AS (
                SELECT
                    p.external_id AS product_id,
                    COALESCE(p.filters->'25', '[]'::jsonb) AS slot25,
                    (
                        (p.stock IS NOT NULL AND p.stock > 0)
                        OR EXISTS (
                            SELECT 1 FROM shopwired.product_variations v
                            WHERE v.product_external_id = p.external_id
                              AND v.stock > 0
                        )
                    ) AS is_in_stock
                FROM shopwired.products p
            ),
            desired AS (
                SELECT
                    pss.product_id,
                    pss.slot25,
                    CASE
                        WHEN pss.is_in_stock THEN '["Next Day Delivery Available"]'::jsonb
                        ELSE '[]'::jsonb
                    END AS desired_filter_values
                FROM product_stock_state pss
            ),
            diff AS (
                SELECT
                    product_id,
                    COALESCE(
                        (
                            SELECT jsonb_agg(value ORDER BY value)
                            FROM jsonb_array_elements_text(slot25) AS value
                        ),
                        '[]'::jsonb
                    ) AS current_sorted,
                    desired_filter_values
                FROM desired
            )
            SELECT product_id, desired_filter_values
            FROM diff
            WHERE current_sorted IS DISTINCT FROM desired_filter_values
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS catalog.products_with_changed_shipping_options_filters');
    }
};
