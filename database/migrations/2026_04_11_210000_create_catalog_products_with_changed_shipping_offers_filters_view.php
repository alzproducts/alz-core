<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * View that flags products whose ShopWired "Shipping Offers" filter
 * (optionNo 20) has drifted from the canonical `free_delivery` custom-field rule.
 *
 * Mapping:
 *   free_delivery = 'Standard' → ["Free Standard Delivery"]
 *   free_delivery = 'Express'  → ["Free Express Delivery"]
 *   otherwise                  → []  (slot cleared)
 *
 * Slot 20 is a dedicated group — no admin-maintained siblings. No
 * merge-preserving strip/re-append logic is needed.
 *
 * Diff is order-insensitive on the current-slot side: `jsonb_agg(... ORDER BY value)`
 * sorts the existing `filters->'20'` values before comparison, so a storefront-reordered
 * slot doesn't produce a spurious drift row. The desired values are always a fresh
 * single-element literal (or `[]`), so no sort is required on that side.
 */
return new class extends Migration {
    public function up(): void
    {
        // 20 = FilterGroupOptionNo::ShippingOffers (keep in sync with FilterGroupOptionNo.php)
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW catalog.products_with_changed_shipping_offers_filters AS
            WITH desired AS (
                SELECT
                    p.external_id AS product_id,
                    COALESCE(p.filters->'20', '[]'::jsonb) AS slot20,
                    CASE
                        WHEN p.custom_fields->>'free_delivery' = 'Standard'
                            THEN '["Free Standard Delivery"]'::jsonb
                        WHEN p.custom_fields->>'free_delivery' = 'Express'
                            THEN '["Free Express Delivery"]'::jsonb
                        ELSE '[]'::jsonb
                    END AS desired_filter_values
                FROM shopwired.products p
            ),
            diff AS (
                SELECT
                    product_id,
                    COALESCE(
                        (
                            SELECT jsonb_agg(value ORDER BY value)
                            FROM jsonb_array_elements_text(slot20) AS value
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
        DB::statement('DROP VIEW IF EXISTS catalog.products_with_changed_shipping_offers_filters');
    }
};
