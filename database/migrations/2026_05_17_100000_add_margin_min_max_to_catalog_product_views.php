<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds variation-aware margin min/max columns to catalog.products_view and
 * removes the parent-level net_margin_single_unit column (added in 2026_05_16_100001,
 * not consumed by any PHP code).
 *
 * New columns on products_view:
 *   profit_margin_min          — COALESCE(parent profit_margin, MIN(variation profit_margin))
 *   profit_margin_max          — COALESCE(parent profit_margin, MAX(variation profit_margin))
 *   net_margin_single_unit_min — COALESCE(parent net_margin,    MIN(variation net_margin_single_unit))
 *   net_margin_single_unit_max — COALESCE(parent net_margin,    MAX(variation net_margin_single_unit))
 *
 * The parent_margins CTE absorbs the linnworks and free_delivery_shipping_costs joins
 * previously inline in the main FROM, and is the single source of truth for the
 * margin formula. products_view now depends on product_variations_view via LATERAL,
 * so the DROP+CREATE order is reversed from 2026_05_16_100001:
 *   up:   DROP products → DROP variations → CREATE variations → CREATE products
 *   down: DROP products → DROP variations → CREATE variations → CREATE products (restored)
 *
 * catalog.product_variations_view is recreated identically — no changes to its definition.
 *
 * up bodies are based on 2026_05_16_100001_add_net_margin_single_unit_to_catalog_product_views::up().
 * down restores that same state verbatim.
 */
return new class extends Migration {
    public function up(): void
    {
        // Drop products_view first — it will reference product_variations_view via LATERAL,
        // so product_variations_view must exist when products_view is created.
        DB::statement('DROP VIEW IF EXISTS catalog.products_view');
        DB::statement('DROP VIEW IF EXISTS catalog.product_variations_view');

        // Recreate product_variations_view identically — no changes to its definition.
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

                -- Computed: worst-case gross margin % when a single unit absorbs full shipping cost.
                -- NULL when cost_price is missing/zero — consistent with profit_margin.
                CASE
                    WHEN s.purchase_price > 0 AND pr.effective_price_net > 0
                        THEN ROUND(
                            (pr.effective_price_net - (s.purchase_price + COALESCE(fdsc.cost, 0)))
                            / pr.effective_price_net * 100,
                            2
                        )
                    ELSE NULL
                END AS net_margin_single_unit,

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
            -- Free delivery shipping cost absorbed per single-unit order
            -- (matches 'Standard'/'Express' on the parent product's custom field)
            LEFT JOIN catalog.free_delivery_shipping_costs fdsc
                ON p.custom_fields->>'free_delivery' = fdsc.delivery_type
        SQL);

        // Create new products_view: adds parent_margins CTE + LATERAL variation aggregates.
        // net_margin_single_unit is removed; 4 new min/max columns are added.
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
                COALESCE(pm.parent_net_margin,    vm.var_net_margin_max)   AS net_margin_single_unit_max

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
        SQL);
    }

    public function down(): void
    {
        // Drop products_view first — in the up() state it depends on product_variations_view via LATERAL.
        DB::statement('DROP VIEW IF EXISTS catalog.products_view');
        DB::statement('DROP VIEW IF EXISTS catalog.product_variations_view');

        // Restore catalog.product_variations_view verbatim from
        // 2026_05_16_100001_add_net_margin_single_unit_to_catalog_product_views::up()
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

                -- Computed: worst-case gross margin % when a single unit absorbs full shipping cost.
                -- NULL when cost_price is missing/zero — consistent with profit_margin.
                CASE
                    WHEN s.purchase_price > 0 AND pr.effective_price_net > 0
                        THEN ROUND(
                            (pr.effective_price_net - (s.purchase_price + COALESCE(fdsc.cost, 0)))
                            / pr.effective_price_net * 100,
                            2
                        )
                    ELSE NULL
                END AS net_margin_single_unit,

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
            -- Free delivery shipping cost absorbed per single-unit order
            -- (matches 'Standard'/'Express' on the parent product's custom field)
            LEFT JOIN catalog.free_delivery_shipping_costs fdsc
                ON p.custom_fields->>'free_delivery' = fdsc.delivery_type
        SQL);

        // Restore catalog.products_view verbatim from
        // 2026_05_16_100001_add_net_margin_single_unit_to_catalog_product_views::up()
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
                -- Uses net effective price to cancel VAT before comparing with tax-exclusive cost
                -- Guard `purchase_price > 0` excludes zero-cost suppliers (otherwise margin collapses to 100).
                CASE
                    WHEN s.purchase_price > 0 AND pr.effective_price_net > 0
                        THEN ROUND((pr.effective_price_net - s.purchase_price) / pr.effective_price_net * 100, 2)
                    ELSE NULL
                END AS profit_margin,

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

                -- Computed: worst-case gross margin % when a single unit absorbs full shipping cost.
                -- NULL when cost_price is missing/zero — consistent with profit_margin.
                CASE
                    WHEN s.purchase_price > 0 AND pr.effective_price_net > 0
                        THEN ROUND(
                            (pr.effective_price_net - (s.purchase_price + COALESCE(fdsc.cost, 0)))
                            / pr.effective_price_net * 100,
                            2
                        )
                    ELSE NULL
                END AS net_margin_single_unit

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
            LEFT JOIN catalog.product_popularity_ranking_latest prl
                ON prl.parent_external_id = p.external_id
            LEFT JOIN catalog.product_popularity_ranking_config ppc
                ON ppc.algorithm_version = prl.algorithm_version
            -- Free delivery shipping cost absorbed per single-unit order
            -- (matches 'Standard'/'Express'; non-free-delivery rows have no match → NULL → COALESCE to 0)
            LEFT JOIN catalog.free_delivery_shipping_costs fdsc
                ON p.custom_fields->>'free_delivery' = fdsc.delivery_type
        SQL);
    }
};
