<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the catalog.product_popularity_ranking view.
 *
 * Expensive write-path view — only read by SyncProductPopularityRankingSnapshotJob.
 * SQL adapted from tmp/product_ranking.sql:
 *   - params CTE reads from catalog.product_popularity_ranking_config WHERE is_active = true
 *   - algorithm_version propagated as the first output column
 *   - sort_order_difference column removed — pure derived data, consumers compute inline
 *
 * The view is not ordered so the snapshot writer can INSERT without an imposed sort.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE VIEW catalog.product_popularity_ranking AS
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
                FROM catalog.product_popularity_ranking_config
                WHERE is_active = true
            ),

            -- ---------------------------------------------------------------
            -- 2. Pull every eligible order line.
            --    order_products.external_id is already the parent product ID —
            --    variant purchases roll up automatically.
            -- ---------------------------------------------------------------
            resolved_lines AS (
                SELECT
                    op.external_id       AS parent_external_id,
                    op.quantity::numeric AS quantity,
                    op.total::numeric    AS turnover,
                    o.order_placed_at
                FROM shopwired.orders_deduplicated o
                JOIN shopwired.order_products_resolved op
                    ON op.order_id = o.id
                WHERE o.lifecycle_status NOT IN ('cancelled', 'refunded')
            ),

            -- ---------------------------------------------------------------
            -- 3. Aggregate both periods in a single pass using conditional
            --    sums. Windows are DISJOINT:
            --      main   = from main_period ago up to recent_period ago
            --      recent = from recent_period ago up to now
            --    This prevents a large recent order inflating both sub-scores.
            -- ---------------------------------------------------------------
            period_totals AS (
                SELECT
                    rl.parent_external_id,

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
                GROUP BY rl.parent_external_id
            ),

            -- ---------------------------------------------------------------
            -- 4. LEFT JOIN sales onto the full product catalog.
            --    has_any_sales partitions sellers from non-sellers.
            -- ---------------------------------------------------------------
            products_with_totals AS (
                SELECT
                    p.external_id                   AS parent_external_id,
                    p.sku,
                    p.title,
                    p.is_active,
                    p.sort_order                    AS current_sort_order,
                    COALESCE(pt.main_qty, 0)        AS main_qty,
                    COALESCE(pt.main_turnover, 0)   AS main_turnover,
                    COALESCE(pt.recent_qty, 0)      AS recent_qty,
                    COALESCE(pt.recent_turnover, 0) AS recent_turnover,
                    (COALESCE(pt.main_qty, 0) + COALESCE(pt.recent_qty, 0)) > 0 AS has_any_sales
                FROM shopwired.products p
                LEFT JOIN period_totals pt
                    ON pt.parent_external_id = p.external_id
            ),

            -- ---------------------------------------------------------------
            -- 5. Percentile-rank each metric — SELLERS ONLY — scaled to
            --    2.00–max_rank. Non-sellers are pinned to 1.00.
            --    PARTITION BY has_any_sales keeps sellers and non-sellers in
            --    separate window pools.
            -- ---------------------------------------------------------------
            ranked AS (
                SELECT
                    pwt.*,
                    CASE WHEN pwt.has_any_sales
                         THEN (2 + (params.max_rank - 2) * PERCENT_RANK() OVER (PARTITION BY pwt.has_any_sales ORDER BY pwt.main_qty))::numeric
                         ELSE 1::numeric
                    END AS main_qty_rank,
                    CASE WHEN pwt.has_any_sales
                         THEN (2 + (params.max_rank - 2) * PERCENT_RANK() OVER (PARTITION BY pwt.has_any_sales ORDER BY pwt.main_turnover))::numeric
                         ELSE 1::numeric
                    END AS main_turnover_rank,
                    CASE WHEN pwt.has_any_sales
                         THEN (2 + (params.max_rank - 2) * PERCENT_RANK() OVER (PARTITION BY pwt.has_any_sales ORDER BY pwt.recent_qty))::numeric
                         ELSE 1::numeric
                    END AS recent_qty_rank,
                    CASE WHEN pwt.has_any_sales
                         THEN (2 + (params.max_rank - 2) * PERCENT_RANK() OVER (PARTITION BY pwt.has_any_sales ORDER BY pwt.recent_turnover))::numeric
                         ELSE 1::numeric
                    END AS recent_turnover_rank
                FROM products_with_totals pwt
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
            -- 8. Final SELECT — all catalog products.
            --    algorithm_version is first so snapshot rows carry full audit.
            --    sort_order_difference omitted — pure derived data.
            -- ---------------------------------------------------------------
            SELECT
                params.algorithm_version,
                fs.parent_external_id,
                fs.sku,
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
        DB::statement('DROP VIEW IF EXISTS catalog.product_popularity_ranking');
    }
};
