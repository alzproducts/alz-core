<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the catalog.sku_popularity_ranking view.
 *
 * Expensive write-path view — only read by SyncSkuPopularityRankingSnapshotJob.
 * Mirrors catalog.product_popularity_ranking but operates at SKU granularity:
 *   - JOINs catalog.sku_aliases to canonicalise order-line SKUs → live_sku
 *   - GROUPs by live_sku instead of parent_external_id
 *   - Seeds the catalog from product_variations + non-varying product SKUs
 *   - Exposes live_sku, parent_external_id, variation_external_id for snapshot rows
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE VIEW catalog.sku_popularity_ranking AS
            WITH
            -- ---------------------------------------------------------------
            -- 1. Read active algorithm parameters from the config table.
            -- ---------------------------------------------------------------
            params AS (
                SELECT
                    algorithm_version,
                    main_period_interval   AS main_period,
                    recent_period_interval AS recent_period,
                    w_main,
                    w_recent,
                    w_qty,
                    w_turnover,
                    max_rank
                FROM catalog.sku_popularity_ranking_config
                WHERE is_active = true
            ),

            -- ---------------------------------------------------------------
            -- 2. Pull every eligible order line, canonicalising the SKU via
            --    catalog.sku_aliases so renamed SKUs aggregate correctly.
            -- ---------------------------------------------------------------
            resolved_lines AS (
                SELECT
                    sa.live_sku,
                    op.quantity::numeric AS quantity,
                    op.total::numeric    AS turnover,
                    o.order_placed_at
                FROM shopwired.orders_deduplicated o
                JOIN shopwired.order_products_resolved op
                    ON op.order_id = o.id
                JOIN catalog.sku_aliases sa
                    ON sa.map_sku = op.sku
                WHERE o.lifecycle_status NOT IN ('cancelled', 'refunded')
                  AND op.sku IS NOT NULL
            ),

            -- ---------------------------------------------------------------
            -- 3. Aggregate both periods in a single pass. Windows are DISJOINT.
            -- ---------------------------------------------------------------
            period_totals AS (
                SELECT
                    rl.live_sku,

                    SUM(CASE WHEN rl.order_placed_at >= NOW() - params.main_period
                              AND rl.order_placed_at <  NOW() - params.recent_period
                             THEN rl.quantity ELSE 0 END) AS main_qty,

                    SUM(CASE WHEN rl.order_placed_at >= NOW() - params.main_period
                              AND rl.order_placed_at <  NOW() - params.recent_period
                             THEN rl.turnover ELSE 0 END) AS main_turnover,

                    SUM(CASE WHEN rl.order_placed_at >= NOW() - params.recent_period
                             THEN rl.quantity ELSE 0 END) AS recent_qty,

                    SUM(CASE WHEN rl.order_placed_at >= NOW() - params.recent_period
                             THEN rl.turnover ELSE 0 END) AS recent_turnover
                FROM resolved_lines rl
                CROSS JOIN params
                WHERE rl.order_placed_at >= NOW() - params.main_period
                GROUP BY rl.live_sku
            ),

            -- ---------------------------------------------------------------
            -- 4. Build the full SKU catalog: variation SKUs + non-varying
            --    product SKUs. LEFT JOIN sales onto it.
            -- ---------------------------------------------------------------
            skus_with_totals AS (
                SELECT
                    cat.live_sku,
                    cat.parent_external_id,
                    cat.variation_external_id,
                    cat.title,
                    cat.is_active,
                    cat.current_sort_order,
                    COALESCE(pt.main_qty, 0)        AS main_qty,
                    COALESCE(pt.main_turnover, 0)   AS main_turnover,
                    COALESCE(pt.recent_qty, 0)      AS recent_qty,
                    COALESCE(pt.recent_turnover, 0) AS recent_turnover,
                    (COALESCE(pt.main_qty, 0) + COALESCE(pt.recent_qty, 0)) > 0 AS has_any_sales
                FROM (
                    -- DISTINCT ON (live_sku) defends against a products.sku
                    -- colliding with a variation.sku on a different product.
                    -- Each table has its own UNIQUE(sku) partial index, but no
                    -- cross-table constraint exists. Without dedup, a collision
                    -- would crash the snapshot INSERT on PK (snapshot_date, live_sku).
                    -- Variation rows sort first (kind = 0) so they win over a
                    -- non-varying product row sharing the same SKU.
                    SELECT DISTINCT ON (live_sku)
                        live_sku,
                        parent_external_id,
                        variation_external_id,
                        title,
                        is_active,
                        current_sort_order
                    FROM (
                        -- Variation SKUs
                        SELECT
                            v.sku           AS live_sku,
                            p.external_id   AS parent_external_id,
                            v.external_id   AS variation_external_id,
                            COALESCE(v.sku || ' — ' || p.title, p.title) AS title,
                            p.is_active,
                            p.sort_order    AS current_sort_order,
                            0               AS kind
                        FROM shopwired.product_variations v
                        JOIN shopwired.products p ON p.id = v.product_id
                        WHERE v.sku IS NOT NULL

                        UNION ALL

                        -- Non-varying product SKUs (products with no variations)
                        SELECT
                            p.sku           AS live_sku,
                            p.external_id   AS parent_external_id,
                            NULL::int       AS variation_external_id,
                            p.title,
                            p.is_active,
                            p.sort_order    AS current_sort_order,
                            1               AS kind
                        FROM shopwired.products p
                        WHERE p.sku IS NOT NULL
                          AND NOT EXISTS (
                              SELECT 1 FROM shopwired.product_variations v
                              WHERE v.product_id = p.id
                          )
                    ) raw_cat
                    ORDER BY live_sku, kind
                ) cat
                LEFT JOIN period_totals pt
                    ON pt.live_sku = cat.live_sku
            ),

            -- ---------------------------------------------------------------
            -- 5. Percentile-rank each metric — SELLERS ONLY — scaled to
            --    2.00–max_rank. Non-sellers are pinned to 1.00.
            -- ---------------------------------------------------------------
            ranked AS (
                SELECT
                    swt.*,
                    CASE WHEN swt.has_any_sales
                         THEN (2 + (params.max_rank - 2) * PERCENT_RANK() OVER (PARTITION BY swt.has_any_sales ORDER BY swt.main_qty))::numeric
                         ELSE 1::numeric
                    END AS main_qty_rank,
                    CASE WHEN swt.has_any_sales
                         THEN (2 + (params.max_rank - 2) * PERCENT_RANK() OVER (PARTITION BY swt.has_any_sales ORDER BY swt.main_turnover))::numeric
                         ELSE 1::numeric
                    END AS main_turnover_rank,
                    CASE WHEN swt.has_any_sales
                         THEN (2 + (params.max_rank - 2) * PERCENT_RANK() OVER (PARTITION BY swt.has_any_sales ORDER BY swt.recent_qty))::numeric
                         ELSE 1::numeric
                    END AS recent_qty_rank,
                    CASE WHEN swt.has_any_sales
                         THEN (2 + (params.max_rank - 2) * PERCENT_RANK() OVER (PARTITION BY swt.has_any_sales ORDER BY swt.recent_turnover))::numeric
                         ELSE 1::numeric
                    END AS recent_turnover_rank
                FROM skus_with_totals swt
                CROSS JOIN params
            ),

            -- ---------------------------------------------------------------
            -- 6. Blend qty + turnover within each period using metric weights.
            -- ---------------------------------------------------------------
            period_scores AS (
                SELECT
                    r.*,
                    (params.w_qty * r.main_qty_rank + params.w_turnover * r.main_turnover_rank)
                        / NULLIF(params.w_qty + params.w_turnover, 0)  AS main_score,
                    (params.w_qty * r.recent_qty_rank + params.w_turnover * r.recent_turnover_rank)
                        / NULLIF(params.w_qty + params.w_turnover, 0)  AS recent_score
                FROM ranked r
                CROSS JOIN params
            ),

            -- ---------------------------------------------------------------
            -- 7. Blend main + recent scores using period weights.
            -- ---------------------------------------------------------------
            final_scores AS (
                SELECT
                    ps.*,
                    (params.w_main * ps.main_score + params.w_recent * ps.recent_score)
                        / NULLIF(params.w_main + params.w_recent, 0)   AS final_score
                FROM period_scores ps
                CROSS JOIN params
            )

            -- ---------------------------------------------------------------
            -- 8. Final SELECT — all catalog SKUs.
            -- ---------------------------------------------------------------
            SELECT
                params.algorithm_version,
                fs.live_sku,
                fs.parent_external_id,
                fs.variation_external_id,
                fs.title,
                fs.is_active,
                ((params.max_rank + 1) - ROUND(fs.final_score)::int) AS calculated_sort_order,
                fs.current_sort_order,
                fs.main_qty,
                ROUND(fs.main_turnover, 2)           AS main_turnover,
                fs.recent_qty,
                ROUND(fs.recent_turnover, 2)         AS recent_turnover,
                ROUND(fs.main_qty_rank, 2)           AS main_qty_rank,
                ROUND(fs.main_turnover_rank, 2)      AS main_turnover_rank,
                ROUND(fs.recent_qty_rank, 2)         AS recent_qty_rank,
                ROUND(fs.recent_turnover_rank, 2)    AS recent_turnover_rank,
                ROUND(fs.main_score, 2)              AS main_score,
                ROUND(fs.recent_score, 2)            AS recent_score,
                ROUND(fs.final_score, 2)             AS final_score,
                ROUND(fs.recent_score - fs.main_score, 2) AS trend
            FROM final_scores fs
            CROSS JOIN params
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS catalog.sku_popularity_ranking');
    }
};
