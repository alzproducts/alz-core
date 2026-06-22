<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Converts catalog.products_view from a regular VIEW to a MATERIALIZED VIEW.
 *
 * EXPLAIN ANALYZE showed 1,143ms per query — 82% on per-product variation aggregation,
 * 11% on recursive category traversal. Materialising eliminates join computation from
 * the read path; reads become a ~5-10ms sequential scan of pre-computed rows.
 *
 * The unique index on `id` is required for REFRESH MATERIALIZED VIEW CONCURRENTLY,
 * which avoids ACCESS EXCLUSIVE locks so readers are never blocked during refresh.
 *
 * NOTE: catalog.products_view is now a materialized view. Future modifications must use
 * DROP MATERIALIZED VIEW / CREATE MATERIALIZED VIEW (not DROP VIEW / CREATE VIEW),
 * and recreate the unique index each time.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS catalog.products_view');

        DB::statement(<<<'SQL'
            CREATE MATERIALIZED VIEW catalog.products_view AS

            -- Tax config: single source of truth for VAT rate (matches PHP TaxRate::standard() = 0.20)
            WITH RECURSIVE tax_config AS (
                SELECT 0.20 AS standard_vat_rate
            ),

            -- Pricing CTE: derive sale state, effective price, and net price for margin calculation
            pricing AS (
                SELECT
                    p.id,
                    p.price,
                    p.sale_price,
                    p.vat_exclusive,
                    (p.sale_price IS NOT NULL AND p.sale_price > 0 AND p.sale_price < p.price) AS is_on_sale,
                    CASE
                        WHEN p.sale_price IS NOT NULL AND p.sale_price > 0 AND p.sale_price < p.price
                            THEN p.sale_price
                        ELSE p.price
                    END AS effective_price,
                    CASE
                        WHEN p.sale_price IS NOT NULL AND p.sale_price > 0 AND p.sale_price < p.price
                            THEN CASE WHEN p.vat_exclusive THEN p.sale_price ELSE p.sale_price / (1 + tc.standard_vat_rate) END
                        ELSE CASE WHEN p.vat_exclusive THEN p.price ELSE p.price / (1 + tc.standard_vat_rate) END
                    END AS effective_price_net
                FROM shopwired.products p
                CROSS JOIN tax_config tc
            ),

            -- Parent-level profit and net margins. Single computation point shared by:
            --   - the main profit_margin column (backwards-compatible)
            --   - the 4 new min/max aggregation columns
            -- Absorbs linnworks and free_delivery_shipping_costs joins from the main FROM,
            -- so those joins are not duplicated for margin-only purposes.
            parent_margins AS (
                SELECT
                    p.id,
                    CASE
                        WHEN s.purchase_price > 0 AND pr.effective_price_net > 0
                            THEN ROUND((pr.effective_price_net - s.purchase_price) / pr.effective_price_net * 100, 2)
                        ELSE NULL
                    END AS parent_profit_margin,
                    CASE
                        WHEN s.purchase_price > 0 AND pr.effective_price_net > 0
                            THEN ROUND(
                                (pr.effective_price_net - (s.purchase_price + COALESCE(fdsc.cost, 0)))
                                / pr.effective_price_net * 100,
                                2
                            )
                        ELSE NULL
                    END AS parent_net_margin
                FROM shopwired.products p
                INNER JOIN pricing pr ON pr.id = p.id
                LEFT JOIN linnworks.stock_items si
                    ON si.item_number = p.sku
                    AND si.item_number IS NOT NULL
                    AND si.item_number != ''
                LEFT JOIN linnworks.stock_item_suppliers s
                    ON s.stock_item_id = si.stock_item_id
                    AND s.is_default = true
                    AND s.purchase_price IS NOT NULL
                LEFT JOIN catalog.free_delivery_shipping_costs fdsc
                    ON p.custom_fields->>'free_delivery' = fdsc.delivery_type
            ),

            -- Step 1: Find main categories by toggle custom field
            main_cats AS (
                SELECT c.external_id
                FROM shopwired.categories c
                WHERE c.custom_fields @> '{"is_main_category": true}'::jsonb
            ),

            -- Step 2: Recursively walk UP the tree to build full ancestor chains.
            -- parent_ids only has the immediate parent, so we recurse until no more parents are found.
            ancestors(cat_id, ancestor_id) AS (
                -- Base: every category is its own ancestor
                SELECT c.external_id, c.external_id
                FROM shopwired.categories c

                UNION ALL

                -- Recursive: find parent of current ancestor
                SELECT a.cat_id, pid.val::int
                FROM ancestors a
                JOIN shopwired.categories c ON c.external_id = a.ancestor_id
                CROSS JOIN LATERAL jsonb_array_elements_text(c.parent_ids) AS pid(val)
            ),

            -- Step 3: Keep only ancestors that are main categories
            cat_main_map AS (
                SELECT DISTINCT a.cat_id, a.ancestor_id AS main_cat_id
                FROM ancestors a
                INNER JOIN main_cats mc ON mc.external_id = a.ancestor_id
            )

            SELECT
                p.id,
                p.external_id,
                p.sku,
                p.gtin,
                p.title,
                p.description,
                p.slug,
                p.url,
                p.price,
                p.sale_price,
                p.compare_price,
                COALESCE(si.quantity, p.stock) AS stock,
                COALESCE(si.available, p.stock, 0) AS available_stock,
                COALESCE(si.quantity, p.stock, 0) AS physical_stock,
                p.is_active,
                p.vat_exclusive,
                p.vat_relief,
                p.weight,
                p.meta_title,
                p.meta_description,
                p.category_ids,
                p.images,
                p.custom_fields,
                p.filters,
                p.sort_order,
                p.shopwired_created_at,
                p.shopwired_updated_at,
                p.created_at,
                p.updated_at,

                -- Computed: sale state and effective price from pricing CTE
                pr.is_on_sale,
                pr.effective_price,

                -- Computed: Linnworks cost price (from default supplier — always tax-exclusive)
                s.purchase_price AS cost_price,

                -- Computed: gross profit margin % — matches PHP ProductView::retailMargin()
                -- Sourced from parent_margins CTE (same value as previous CASE WHEN inline).
                pm.parent_profit_margin AS profit_margin,

                -- Computed: has free delivery designation (non-empty, non-'none' free_delivery custom field)
                (p.custom_fields->>'free_delivery' IS NOT NULL
                 AND p.custom_fields->>'free_delivery' != ''
                 AND p.custom_fields->>'free_delivery' != 'none') AS has_free_delivery,

                -- Computed: main category IDs this product belongs to (directly or via ancestor chain)
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
                ) AS main_category_ids,

                -- Popularity (nullable) — sourced from snapshot pipeline, independent of ShopWired sort_order
                prl.calculated_sort_order AS popularity_rank,
                ppc.max_rank              AS popularity_max,

                -- Variation-aware margin min/max. COALESCE prefers the parent margin when non-NULL
                -- (product has its own SKU + cost price). Falls back to variation aggregates for
                -- variation-only products where the parent has no SKU and thus no direct cost price.
                COALESCE(pm.parent_profit_margin, vm.var_profit_margin_min) AS profit_margin_min,
                COALESCE(pm.parent_profit_margin, vm.var_profit_margin_max) AS profit_margin_max,
                COALESCE(pm.parent_net_margin,    vm.var_net_margin_min)   AS net_margin_single_unit_min,
                COALESCE(pm.parent_net_margin,    vm.var_net_margin_max)   AS net_margin_single_unit_max,

                -- Latest price change across parent SKU + every variation SKU (NULL when no SKU has
                -- a price_periods row yet). Index-backed via the partial unique index on
                -- operations.price_periods(sku) WHERE effective_to IS NULL.
                price_history.latest_price_change AS price_last_updated_at,

                -- Latest cost-price change across parent SKU + every variation SKU (NULL when no SKU
                -- has a cost_price_changes row yet). Sourced from the catalog.cost_price_changes audit
                -- trail (#876); every row is a real change, so MAX(changed_at) with no SCD2 filter.
                cost_price_history.latest_cost_price_change AS cost_price_last_updated_at

            FROM shopwired.products p
            INNER JOIN pricing pr ON pr.id = p.id
            INNER JOIN parent_margins pm ON pm.id = p.id
            LEFT JOIN linnworks.stock_items si
                ON si.item_number = p.sku
                AND si.item_number IS NOT NULL
                AND si.item_number != ''
            LEFT JOIN linnworks.stock_item_suppliers s
                ON s.stock_item_id = si.stock_item_id
                AND s.is_default = true
                AND s.purchase_price IS NOT NULL
            LEFT JOIN catalog.product_popularity_ranking_latest prl
                ON prl.parent_external_id = p.external_id
            LEFT JOIN catalog.product_popularity_ranking_config ppc
                ON ppc.algorithm_version = prl.algorithm_version
            -- Variation-aggregated margins: NULL for products with no variations.
            -- One scan of product_variations_view per product row.
            LEFT JOIN LATERAL (
                SELECT
                    MIN(pv.profit_margin)          AS var_profit_margin_min,
                    MAX(pv.profit_margin)          AS var_profit_margin_max,
                    MIN(pv.net_margin_single_unit) AS var_net_margin_min,
                    MAX(pv.net_margin_single_unit) AS var_net_margin_max
                FROM catalog.product_variations_view pv
                WHERE pv.product_id = p.id
            ) vm ON true
            -- Latest active price-period across parent + every variation SKU.
            -- UNION ALL of every SKU belonging to the product, then MAX(effective_from)
            -- across the join into operations.price_periods (current rows only).
            LEFT JOIN LATERAL (
                SELECT MAX(pp.effective_from) AS latest_price_change
                FROM (
                    SELECT p.sku WHERE p.sku IS NOT NULL
                    UNION ALL
                    SELECT v.sku FROM shopwired.product_variations v
                    WHERE v.product_id = p.id AND v.sku IS NOT NULL
                ) all_skus(sku)
                INNER JOIN operations.price_periods pp
                    ON pp.sku = all_skus.sku
                    AND pp.effective_to IS NULL
            ) price_history ON true
            -- Latest cost-price change across parent + every variation SKU.
            -- Same UNION ALL of all SKUs, then MAX(changed_at) across the join into
            -- catalog.cost_price_changes. No SCD2 filter — every row is a real change.
            -- Index-backed via catalog.cost_price_changes(sku, supplier_id) (leading column sku).
            LEFT JOIN LATERAL (
                SELECT MAX(cpc.changed_at) AS latest_cost_price_change
                FROM (
                    SELECT p.sku WHERE p.sku IS NOT NULL
                    UNION ALL
                    SELECT v.sku FROM shopwired.product_variations v
                    WHERE v.product_id = p.id AND v.sku IS NOT NULL
                ) all_skus(sku)
                INNER JOIN catalog.cost_price_changes cpc
                    ON cpc.sku = all_skus.sku
            ) cost_price_history ON true
        SQL);

        DB::statement('CREATE UNIQUE INDEX idx_catalog_products_view_id ON catalog.products_view (id)');
    }

    public function down(): void
    {
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS catalog.products_view');

        DB::statement(<<<'SQL'
            CREATE VIEW catalog.products_view AS

            -- Tax config: single source of truth for VAT rate (matches PHP TaxRate::standard() = 0.20)
            WITH RECURSIVE tax_config AS (
                SELECT 0.20 AS standard_vat_rate
            ),

            -- Pricing CTE: derive sale state, effective price, and net price for margin calculation
            pricing AS (
                SELECT
                    p.id,
                    p.price,
                    p.sale_price,
                    p.vat_exclusive,
                    (p.sale_price IS NOT NULL AND p.sale_price > 0 AND p.sale_price < p.price) AS is_on_sale,
                    CASE
                        WHEN p.sale_price IS NOT NULL AND p.sale_price > 0 AND p.sale_price < p.price
                            THEN p.sale_price
                        ELSE p.price
                    END AS effective_price,
                    CASE
                        WHEN p.sale_price IS NOT NULL AND p.sale_price > 0 AND p.sale_price < p.price
                            THEN CASE WHEN p.vat_exclusive THEN p.sale_price ELSE p.sale_price / (1 + tc.standard_vat_rate) END
                        ELSE CASE WHEN p.vat_exclusive THEN p.price ELSE p.price / (1 + tc.standard_vat_rate) END
                    END AS effective_price_net
                FROM shopwired.products p
                CROSS JOIN tax_config tc
            ),

            -- Parent-level profit and net margins. Single computation point shared by:
            --   - the main profit_margin column (backwards-compatible)
            --   - the 4 new min/max aggregation columns
            -- Absorbs linnworks and free_delivery_shipping_costs joins from the main FROM,
            -- so those joins are not duplicated for margin-only purposes.
            parent_margins AS (
                SELECT
                    p.id,
                    CASE
                        WHEN s.purchase_price > 0 AND pr.effective_price_net > 0
                            THEN ROUND((pr.effective_price_net - s.purchase_price) / pr.effective_price_net * 100, 2)
                        ELSE NULL
                    END AS parent_profit_margin,
                    CASE
                        WHEN s.purchase_price > 0 AND pr.effective_price_net > 0
                            THEN ROUND(
                                (pr.effective_price_net - (s.purchase_price + COALESCE(fdsc.cost, 0)))
                                / pr.effective_price_net * 100,
                                2
                            )
                        ELSE NULL
                    END AS parent_net_margin
                FROM shopwired.products p
                INNER JOIN pricing pr ON pr.id = p.id
                LEFT JOIN linnworks.stock_items si
                    ON si.item_number = p.sku
                    AND si.item_number IS NOT NULL
                    AND si.item_number != ''
                LEFT JOIN linnworks.stock_item_suppliers s
                    ON s.stock_item_id = si.stock_item_id
                    AND s.is_default = true
                    AND s.purchase_price IS NOT NULL
                LEFT JOIN catalog.free_delivery_shipping_costs fdsc
                    ON p.custom_fields->>'free_delivery' = fdsc.delivery_type
            ),

            -- Step 1: Find main categories by toggle custom field
            main_cats AS (
                SELECT c.external_id
                FROM shopwired.categories c
                WHERE c.custom_fields @> '{"is_main_category": true}'::jsonb
            ),

            -- Step 2: Recursively walk UP the tree to build full ancestor chains.
            -- parent_ids only has the immediate parent, so we recurse until no more parents are found.
            ancestors(cat_id, ancestor_id) AS (
                -- Base: every category is its own ancestor
                SELECT c.external_id, c.external_id
                FROM shopwired.categories c

                UNION ALL

                -- Recursive: find parent of current ancestor
                SELECT a.cat_id, pid.val::int
                FROM ancestors a
                JOIN shopwired.categories c ON c.external_id = a.ancestor_id
                CROSS JOIN LATERAL jsonb_array_elements_text(c.parent_ids) AS pid(val)
            ),

            -- Step 3: Keep only ancestors that are main categories
            cat_main_map AS (
                SELECT DISTINCT a.cat_id, a.ancestor_id AS main_cat_id
                FROM ancestors a
                INNER JOIN main_cats mc ON mc.external_id = a.ancestor_id
            )

            SELECT
                p.id,
                p.external_id,
                p.sku,
                p.gtin,
                p.title,
                p.description,
                p.slug,
                p.url,
                p.price,
                p.sale_price,
                p.compare_price,
                COALESCE(si.quantity, p.stock) AS stock,
                COALESCE(si.available, p.stock, 0) AS available_stock,
                COALESCE(si.quantity, p.stock, 0) AS physical_stock,
                p.is_active,
                p.vat_exclusive,
                p.vat_relief,
                p.weight,
                p.meta_title,
                p.meta_description,
                p.category_ids,
                p.images,
                p.custom_fields,
                p.filters,
                p.sort_order,
                p.shopwired_created_at,
                p.shopwired_updated_at,
                p.created_at,
                p.updated_at,

                -- Computed: sale state and effective price from pricing CTE
                pr.is_on_sale,
                pr.effective_price,

                -- Computed: Linnworks cost price (from default supplier — always tax-exclusive)
                s.purchase_price AS cost_price,

                -- Computed: gross profit margin % — matches PHP ProductView::retailMargin()
                -- Sourced from parent_margins CTE (same value as previous CASE WHEN inline).
                pm.parent_profit_margin AS profit_margin,

                -- Computed: has free delivery designation (non-empty, non-'none' free_delivery custom field)
                (p.custom_fields->>'free_delivery' IS NOT NULL
                 AND p.custom_fields->>'free_delivery' != ''
                 AND p.custom_fields->>'free_delivery' != 'none') AS has_free_delivery,

                -- Computed: main category IDs this product belongs to (directly or via ancestor chain)
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
                ) AS main_category_ids,

                -- Popularity (nullable) — sourced from snapshot pipeline, independent of ShopWired sort_order
                prl.calculated_sort_order AS popularity_rank,
                ppc.max_rank              AS popularity_max,

                -- Variation-aware margin min/max. COALESCE prefers the parent margin when non-NULL
                -- (product has its own SKU + cost price). Falls back to variation aggregates for
                -- variation-only products where the parent has no SKU and thus no direct cost price.
                COALESCE(pm.parent_profit_margin, vm.var_profit_margin_min) AS profit_margin_min,
                COALESCE(pm.parent_profit_margin, vm.var_profit_margin_max) AS profit_margin_max,
                COALESCE(pm.parent_net_margin,    vm.var_net_margin_min)   AS net_margin_single_unit_min,
                COALESCE(pm.parent_net_margin,    vm.var_net_margin_max)   AS net_margin_single_unit_max,

                -- Latest price change across parent SKU + every variation SKU (NULL when no SKU has
                -- a price_periods row yet). Index-backed via the partial unique index on
                -- operations.price_periods(sku) WHERE effective_to IS NULL.
                price_history.latest_price_change AS price_last_updated_at,

                -- Latest cost-price change across parent SKU + every variation SKU (NULL when no SKU
                -- has a cost_price_changes row yet). Sourced from the catalog.cost_price_changes audit
                -- trail (#876); every row is a real change, so MAX(changed_at) with no SCD2 filter.
                cost_price_history.latest_cost_price_change AS cost_price_last_updated_at

            FROM shopwired.products p
            INNER JOIN pricing pr ON pr.id = p.id
            INNER JOIN parent_margins pm ON pm.id = p.id
            LEFT JOIN linnworks.stock_items si
                ON si.item_number = p.sku
                AND si.item_number IS NOT NULL
                AND si.item_number != ''
            LEFT JOIN linnworks.stock_item_suppliers s
                ON s.stock_item_id = si.stock_item_id
                AND s.is_default = true
                AND s.purchase_price IS NOT NULL
            LEFT JOIN catalog.product_popularity_ranking_latest prl
                ON prl.parent_external_id = p.external_id
            LEFT JOIN catalog.product_popularity_ranking_config ppc
                ON ppc.algorithm_version = prl.algorithm_version
            -- Variation-aggregated margins: NULL for products with no variations.
            -- One scan of product_variations_view per product row.
            LEFT JOIN LATERAL (
                SELECT
                    MIN(pv.profit_margin)          AS var_profit_margin_min,
                    MAX(pv.profit_margin)          AS var_profit_margin_max,
                    MIN(pv.net_margin_single_unit) AS var_net_margin_min,
                    MAX(pv.net_margin_single_unit) AS var_net_margin_max
                FROM catalog.product_variations_view pv
                WHERE pv.product_id = p.id
            ) vm ON true
            -- Latest active price-period across parent + every variation SKU.
            -- UNION ALL of every SKU belonging to the product, then MAX(effective_from)
            -- across the join into operations.price_periods (current rows only).
            LEFT JOIN LATERAL (
                SELECT MAX(pp.effective_from) AS latest_price_change
                FROM (
                    SELECT p.sku WHERE p.sku IS NOT NULL
                    UNION ALL
                    SELECT v.sku FROM shopwired.product_variations v
                    WHERE v.product_id = p.id AND v.sku IS NOT NULL
                ) all_skus(sku)
                INNER JOIN operations.price_periods pp
                    ON pp.sku = all_skus.sku
                    AND pp.effective_to IS NULL
            ) price_history ON true
            -- Latest cost-price change across parent + every variation SKU.
            -- Same UNION ALL of all SKUs, then MAX(changed_at) across the join into
            -- catalog.cost_price_changes. No SCD2 filter — every row is a real change.
            -- Index-backed via catalog.cost_price_changes(sku, supplier_id) (leading column sku).
            LEFT JOIN LATERAL (
                SELECT MAX(cpc.changed_at) AS latest_cost_price_change
                FROM (
                    SELECT p.sku WHERE p.sku IS NOT NULL
                    UNION ALL
                    SELECT v.sku FROM shopwired.product_variations v
                    WHERE v.product_id = p.id AND v.sku IS NOT NULL
                ) all_skus(sku)
                INNER JOIN catalog.cost_price_changes cpc
                    ON cpc.sku = all_skus.sku
            ) cost_price_history ON true
        SQL);
    }
};
