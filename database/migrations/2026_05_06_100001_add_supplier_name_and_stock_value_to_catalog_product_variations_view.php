<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds default_supplier_name and stock_value columns to catalog.product_variations_view.
 *
 * default_supplier_name enables server-side WHERE filtering by supplier.
 * stock_value = purchase_price × available stock (NULL when no supplier cost).
 *
 * No new JOINs — both columns derive from the existing `s` (stock_item_suppliers) alias.
 *
 * Down restores the 2026_05_02_100001 definition (parent columns migration).
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS catalog.product_variations_view');

        DB::statement(<<<'SQL'
            CREATE VIEW catalog.product_variations_view AS

            -- Tax config: single source of truth for VAT rate (matches PHP TaxRate::standard() = 0.20)
            WITH RECURSIVE tax_config AS (
                SELECT 0.20 AS standard_vat_rate
            ),

            -- Pricing CTE 1: resolve price inheritance from parent via COALESCE
            base_pricing AS (
                SELECT
                    v.id,
                    COALESCE(v.price, p.price) AS price,
                    COALESCE(v.sale_price, p.sale_price) AS sale_price,
                    p.vat_exclusive
                FROM shopwired.product_variations v
                INNER JOIN shopwired.products p ON p.id = v.product_id
            ),

            -- Pricing CTE 2: derive sale state, effective price, net price (same logic as products_view)
            pricing AS (
                SELECT
                    bp.id,
                    bp.price,
                    bp.sale_price,
                    (bp.sale_price IS NOT NULL AND bp.sale_price > 0 AND bp.sale_price < bp.price) AS is_on_sale,
                    CASE
                        WHEN bp.sale_price IS NOT NULL AND bp.sale_price > 0 AND bp.sale_price < bp.price
                            THEN bp.sale_price
                        ELSE bp.price
                    END AS effective_price,
                    CASE
                        WHEN bp.sale_price IS NOT NULL AND bp.sale_price > 0 AND bp.sale_price < bp.price
                            THEN CASE WHEN bp.vat_exclusive THEN bp.sale_price ELSE bp.sale_price / (1 + tc.standard_vat_rate) END
                        ELSE CASE WHEN bp.vat_exclusive THEN bp.price ELSE bp.price / (1 + tc.standard_vat_rate) END
                    END AS effective_price_net
                FROM base_pricing bp
                CROSS JOIN tax_config tc
            ),

            -- Main categories: find categories flagged as main via custom field
            main_cats AS (
                SELECT c.external_id
                FROM shopwired.categories c
                WHERE c.custom_fields @> '{"is_main_category": true}'::jsonb
            ),

            -- Category ancestry: recursively walk UP the tree via parent_ids JSONB
            ancestors(cat_id, ancestor_id) AS (
                SELECT c.external_id, c.external_id
                FROM shopwired.categories c

                UNION ALL

                SELECT a.cat_id, pid.val::int
                FROM ancestors a
                JOIN shopwired.categories c ON c.external_id = a.ancestor_id
                CROSS JOIN LATERAL jsonb_array_elements_text(c.parent_ids) AS pid(val)
            ),

            -- Main category mapping: filter ancestors to only main categories
            cat_main_map AS (
                SELECT DISTINCT a.cat_id, a.ancestor_id AS main_cat_id
                FROM ancestors a
                INNER JOIN main_cats mc ON mc.external_id = a.ancestor_id
            )

            SELECT
                v.id,
                v.product_id,
                v.product_external_id,
                v.external_id,
                v.sku,
                v.stock,
                COALESCE(si.available, v.stock, 0) AS available_stock,
                COALESCE(si.quantity, v.stock, 0) AS physical_stock,
                v.weight,
                v.gtin,
                v.mpn,
                v.image_index,
                v.options,
                v.created_at,
                v.updated_at,

                -- Raw prices (before parent inheritance — kept for debugging/transparency)
                v.price AS raw_price,
                v.sale_price AS raw_sale_price,

                -- Resolved prices (from pricing CTEs after COALESCE inheritance)
                pr.price,
                pr.sale_price,
                pr.is_on_sale,
                pr.effective_price,

                -- Computed: Linnworks cost price (by variation's own SKU)
                s.purchase_price AS cost_price,

                -- Computed: gross profit margin % (same formula as products_view)
                -- Guard `purchase_price > 0` excludes zero-cost suppliers (otherwise margin collapses to 100).
                CASE
                    WHEN s.purchase_price > 0 AND pr.effective_price_net > 0
                        THEN ROUND((pr.effective_price_net - s.purchase_price) / pr.effective_price_net * 100, 2)
                    ELSE NULL
                END AS profit_margin,

                -- Popularity (nullable) — sourced from SKU snapshot pipeline
                srl.calculated_sort_order AS popularity_rank,
                spc.max_rank              AS popularity_max,

                -- Default supplier name for SQL filtering (NULL when no cost price set)
                s.supplier_name AS default_supplier_name,

                -- Stock value = cost × available units (NULL when no supplier cost).
                -- GREATEST(..., 0) clamps oversold/negative `available` to 0 so the result
                -- never violates Money's non-negative invariant in PHP.
                s.purchase_price * GREATEST(COALESCE(si.available, v.stock, 0), 0) AS stock_value,

                -- Computed: variation title = parent title + ' - ' + space-separated option value_names.
                -- Outer COALESCE guards against options arrays whose elements lack 'value_name'
                -- (string_agg returns NULL → concat collapses to NULL → fall back to bare title).
                CASE
                    WHEN v.options IS NOT NULL AND jsonb_array_length(v.options) > 0
                        THEN COALESCE(
                            p.title || ' - ' || (
                                SELECT string_agg(elem->>'value_name', ' ' ORDER BY ordinality)
                                FROM jsonb_array_elements(v.options) WITH ORDINALITY AS t(elem, ordinality)
                            ),
                            p.title
                        )
                    ELSE p.title
                END AS variation_title,

                -- Filter columns (required for SQL WHERE clauses — not available via eager-load)
                p.is_active              AS parent_is_active,

                -- Computed: has free delivery (non-empty, non-'none' free_delivery custom field)
                (p.custom_fields->>'free_delivery' IS NOT NULL
                 AND p.custom_fields->>'free_delivery' != ''
                 AND p.custom_fields->>'free_delivery' != 'none') AS parent_has_free_delivery,

                -- Computed: main category IDs from parent product's categories
                COALESCE(
                    (SELECT jsonb_agg(sub.main_cat_id)
                     FROM (
                         SELECT DISTINCT cmm.main_cat_id
                         FROM jsonb_array_elements_text(p.category_ids) AS elem(cat_id)
                         JOIN cat_main_map cmm ON cmm.cat_id = elem.cat_id::int
                         ORDER BY cmm.main_cat_id
                     ) sub
                    ),
                    '[]'::jsonb
                ) AS parent_main_category_ids

            FROM shopwired.product_variations v
            INNER JOIN pricing pr ON pr.id = v.id
            INNER JOIN shopwired.products p ON p.id = v.product_id
            LEFT JOIN linnworks.stock_items si
                ON si.item_number = v.sku
                AND si.item_number IS NOT NULL
                AND si.item_number != ''
            LEFT JOIN linnworks.stock_item_suppliers s
                ON s.stock_item_id = si.stock_item_id
                AND s.is_default = true
                AND s.purchase_price IS NOT NULL
            LEFT JOIN catalog.sku_popularity_ranking_latest srl
                ON srl.live_sku = v.sku
            LEFT JOIN catalog.sku_popularity_ranking_config spc
                ON spc.algorithm_version = srl.algorithm_version
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS catalog.product_variations_view');

        DB::statement(<<<'SQL'
            CREATE VIEW catalog.product_variations_view AS

            -- Tax config: single source of truth for VAT rate (matches PHP TaxRate::standard() = 0.20)
            WITH RECURSIVE tax_config AS (
                SELECT 0.20 AS standard_vat_rate
            ),

            -- Pricing CTE 1: resolve price inheritance from parent via COALESCE
            base_pricing AS (
                SELECT
                    v.id,
                    COALESCE(v.price, p.price) AS price,
                    COALESCE(v.sale_price, p.sale_price) AS sale_price,
                    p.vat_exclusive
                FROM shopwired.product_variations v
                INNER JOIN shopwired.products p ON p.id = v.product_id
            ),

            -- Pricing CTE 2: derive sale state, effective price, net price (same logic as products_view)
            pricing AS (
                SELECT
                    bp.id,
                    bp.price,
                    bp.sale_price,
                    (bp.sale_price IS NOT NULL AND bp.sale_price > 0 AND bp.sale_price < bp.price) AS is_on_sale,
                    CASE
                        WHEN bp.sale_price IS NOT NULL AND bp.sale_price > 0 AND bp.sale_price < bp.price
                            THEN bp.sale_price
                        ELSE bp.price
                    END AS effective_price,
                    CASE
                        WHEN bp.sale_price IS NOT NULL AND bp.sale_price > 0 AND bp.sale_price < bp.price
                            THEN CASE WHEN bp.vat_exclusive THEN bp.sale_price ELSE bp.sale_price / (1 + tc.standard_vat_rate) END
                        ELSE CASE WHEN bp.vat_exclusive THEN bp.price ELSE bp.price / (1 + tc.standard_vat_rate) END
                    END AS effective_price_net
                FROM base_pricing bp
                CROSS JOIN tax_config tc
            ),

            -- Main categories: find categories flagged as main via custom field
            main_cats AS (
                SELECT c.external_id
                FROM shopwired.categories c
                WHERE c.custom_fields @> '{"is_main_category": true}'::jsonb
            ),

            -- Category ancestry: recursively walk UP the tree via parent_ids JSONB
            ancestors(cat_id, ancestor_id) AS (
                SELECT c.external_id, c.external_id
                FROM shopwired.categories c

                UNION ALL

                SELECT a.cat_id, pid.val::int
                FROM ancestors a
                JOIN shopwired.categories c ON c.external_id = a.ancestor_id
                CROSS JOIN LATERAL jsonb_array_elements_text(c.parent_ids) AS pid(val)
            ),

            -- Main category mapping: filter ancestors to only main categories
            cat_main_map AS (
                SELECT DISTINCT a.cat_id, a.ancestor_id AS main_cat_id
                FROM ancestors a
                INNER JOIN main_cats mc ON mc.external_id = a.ancestor_id
            )

            SELECT
                v.id,
                v.product_id,
                v.product_external_id,
                v.external_id,
                v.sku,
                v.stock,
                COALESCE(si.available, v.stock, 0) AS available_stock,
                COALESCE(si.quantity, v.stock, 0) AS physical_stock,
                v.weight,
                v.gtin,
                v.mpn,
                v.image_index,
                v.options,
                v.created_at,
                v.updated_at,

                -- Raw prices (before parent inheritance — kept for debugging/transparency)
                v.price AS raw_price,
                v.sale_price AS raw_sale_price,

                -- Resolved prices (from pricing CTEs after COALESCE inheritance)
                pr.price,
                pr.sale_price,
                pr.is_on_sale,
                pr.effective_price,

                -- Computed: Linnworks cost price (by variation's own SKU)
                s.purchase_price AS cost_price,

                -- Computed: gross profit margin % (same formula as products_view)
                -- Guard `purchase_price > 0` excludes zero-cost suppliers (otherwise margin collapses to 100).
                CASE
                    WHEN s.purchase_price > 0 AND pr.effective_price_net > 0
                        THEN ROUND((pr.effective_price_net - s.purchase_price) / pr.effective_price_net * 100, 2)
                    ELSE NULL
                END AS profit_margin,

                -- Popularity (nullable) — sourced from SKU snapshot pipeline
                srl.calculated_sort_order AS popularity_rank,
                spc.max_rank              AS popularity_max,

                -- Computed: variation title = parent title + ' - ' + space-separated option value_names.
                -- Outer COALESCE guards against options arrays whose elements lack 'value_name'
                -- (string_agg returns NULL → concat collapses to NULL → fall back to bare title).
                CASE
                    WHEN v.options IS NOT NULL AND jsonb_array_length(v.options) > 0
                        THEN COALESCE(
                            p.title || ' - ' || (
                                SELECT string_agg(elem->>'value_name', ' ' ORDER BY ordinality)
                                FROM jsonb_array_elements(v.options) WITH ORDINALITY AS t(elem, ordinality)
                            ),
                            p.title
                        )
                    ELSE p.title
                END AS variation_title,

                -- Filter columns (required for SQL WHERE clauses — not available via eager-load)
                p.is_active              AS parent_is_active,

                -- Computed: has free delivery (non-empty, non-'none' free_delivery custom field)
                (p.custom_fields->>'free_delivery' IS NOT NULL
                 AND p.custom_fields->>'free_delivery' != ''
                 AND p.custom_fields->>'free_delivery' != 'none') AS parent_has_free_delivery,

                -- Computed: main category IDs from parent product's categories
                COALESCE(
                    (SELECT jsonb_agg(sub.main_cat_id)
                     FROM (
                         SELECT DISTINCT cmm.main_cat_id
                         FROM jsonb_array_elements_text(p.category_ids) AS elem(cat_id)
                         JOIN cat_main_map cmm ON cmm.cat_id = elem.cat_id::int
                         ORDER BY cmm.main_cat_id
                     ) sub
                    ),
                    '[]'::jsonb
                ) AS parent_main_category_ids

            FROM shopwired.product_variations v
            INNER JOIN pricing pr ON pr.id = v.id
            INNER JOIN shopwired.products p ON p.id = v.product_id
            LEFT JOIN linnworks.stock_items si
                ON si.item_number = v.sku
                AND si.item_number IS NOT NULL
                AND si.item_number != ''
            LEFT JOIN linnworks.stock_item_suppliers s
                ON s.stock_item_id = si.stock_item_id
                AND s.is_default = true
                AND s.purchase_price IS NOT NULL
            LEFT JOIN catalog.sku_popularity_ranking_latest srl
                ON srl.live_sku = v.sku
            LEFT JOIN catalog.sku_popularity_ranking_config spc
                ON spc.algorithm_version = srl.algorithm_version
        SQL);
    }
};
