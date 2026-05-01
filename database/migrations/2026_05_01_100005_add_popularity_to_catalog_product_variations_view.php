<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extends catalog.product_variations_view with popularity_rank + popularity_max
 * columns by LEFT JOINing to the SKU popularity ranking pipeline.
 *
 * Same migration pattern as 2026_04_21_041810_add_popularity_to_catalog_products_view.
 * PostgreSQL views can't be ALTERed — must DROP + full CREATE.
 *
 * Up body is copied from 2026_04_24_120000_fix_catalog_views_margin_zero_cost::up()
 * (the current live definition) with the popularity LEFT JOINs appended.
 *
 * Down restores the pre-popularity definition from that same migration.
 */
return new class extends Migration {
    public function up(): void
    {
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
                -- Guard `purchase_price > 0` excludes zero-cost suppliers (otherwise margin collapses to 100).
                CASE
                    WHEN s.purchase_price > 0 AND pr.effective_price_net > 0
                        THEN ROUND((pr.effective_price_net - s.purchase_price) / pr.effective_price_net * 100, 2)
                    ELSE NULL
                END AS profit_margin,

                -- Popularity (nullable) — sourced from SKU snapshot pipeline
                srl.calculated_sort_order AS popularity_rank,
                spc.max_rank              AS popularity_max

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
                -- Guard `purchase_price > 0` excludes zero-cost suppliers (otherwise margin collapses to 100).
                CASE
                    WHEN s.purchase_price > 0 AND pr.effective_price_net > 0
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
