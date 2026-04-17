<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds `available_stock` and `physical_stock` columns to both catalog views.
 *
 * - `available_stock` mirrors what the stock sync pushes to ShopWired:
 *   COALESCE(linnworks.stock_items.available, shopwired.stock, 0).
 * - `physical_stock` is the on-hand quantity before order-book allocation:
 *   COALESCE(linnworks.stock_items.quantity, shopwired.stock, 0).
 *
 * Both views already LEFT JOIN linnworks.stock_items via SKU for cost_price;
 * this migration re-uses that `si` alias and adds two new SELECT columns on
 * each view. No JOIN topology change.
 *
 * The up() products_view SQL is copied verbatim from the previous migration
 * (2026_04_16_000001_add_main_category_ids_to_catalog_products_view) with
 * only `available_stock` and `physical_stock` inserted after the `stock`
 * column. The product_variations_view is copied from its creator
 * (2026_03_31_110001) with the same two columns inserted.
 *
 * PostgreSQL views can't be altered — must DROP + full CREATE.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS catalog.products_view');

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
                CASE
                    WHEN s.purchase_price IS NOT NULL AND pr.effective_price_net > 0
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
                ) AS main_category_ids

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
        SQL);

        DB::statement('DROP VIEW IF EXISTS catalog.product_variations_view');

        DB::statement(<<<'SQL'
            CREATE VIEW catalog.product_variations_view AS

            -- Tax config: single source of truth for VAT rate (matches PHP TaxRate::standard() = 0.20)
            WITH tax_config AS (
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
                CASE
                    WHEN s.purchase_price IS NOT NULL AND pr.effective_price_net > 0
                        THEN ROUND((pr.effective_price_net - s.purchase_price) / pr.effective_price_net * 100, 2)
                    ELSE NULL
                END AS profit_margin

            FROM shopwired.product_variations v
            INNER JOIN pricing pr ON pr.id = v.id
            LEFT JOIN linnworks.stock_items si
                ON si.item_number = v.sku
                AND si.item_number IS NOT NULL
                AND si.item_number != ''
            LEFT JOIN linnworks.stock_item_suppliers s
                ON s.stock_item_id = si.stock_item_id
                AND s.is_default = true
                AND s.purchase_price IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS catalog.product_variations_view');
        DB::statement('DROP VIEW IF EXISTS catalog.products_view');

        // Restore products_view to the state left by
        // 2026_04_16_000001_add_main_category_ids_to_catalog_products_view (verbatim).
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
                CASE
                    WHEN s.purchase_price IS NOT NULL AND pr.effective_price_net > 0
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
                ) AS main_category_ids

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
        SQL);

        // Restore product_variations_view to the state left by
        // 2026_03_31_110001_create_catalog_product_views (verbatim).
        DB::statement(<<<'SQL'
            CREATE VIEW catalog.product_variations_view AS

            -- Tax config: single source of truth for VAT rate (matches PHP TaxRate::standard() = 0.20)
            WITH tax_config AS (
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
            )

            SELECT
                v.id,
                v.product_id,
                v.product_external_id,
                v.external_id,
                v.sku,
                v.stock,
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
                CASE
                    WHEN s.purchase_price IS NOT NULL AND pr.effective_price_net > 0
                        THEN ROUND((pr.effective_price_net - s.purchase_price) / pr.effective_price_net * 100, 2)
                    ELSE NULL
                END AS profit_margin

            FROM shopwired.product_variations v
            INNER JOIN pricing pr ON pr.id = v.id
            LEFT JOIN linnworks.stock_items si
                ON si.item_number = v.sku
                AND si.item_number IS NOT NULL
                AND si.item_number != ''
            LEFT JOIN linnworks.stock_item_suppliers s
                ON s.stock_item_id = si.stock_item_id
                AND s.is_default = true
                AND s.purchase_price IS NOT NULL
        SQL);
    }
};
