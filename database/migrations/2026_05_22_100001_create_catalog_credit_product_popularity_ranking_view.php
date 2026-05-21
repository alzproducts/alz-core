<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the catalog.credit_product_popularity_ranking view.
 *
 * Adapted from tmp/credit-customer-popularity.sql:
 *   - params CTE reads from catalog.credit_product_popularity_ranking_config WHERE is_active = true
 *   - algorithm_version propagated as the first output column (matching existing view contract)
 *   - resolved_lines filtered to is_credit_enabled = true customers only
 *   - OVERLAPPING windows: main = 12mo, recent = 3mo (both extend to NOW())
 *   - credit_tier column (Tier 1/2/3 or NULL) added via PARTITION BY has_any_sales ROW_NUMBER
 *   - NO final WHERE filter — outputs all products (consumers filter as needed)
 *
 * Dropped from prototype: RECURSIVE main_cats/ancestors CTEs, main_categories column,
 * category_ids column — those are for ad-hoc tuning, not the snapshot pipeline.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE VIEW catalog.credit_product_popularity_ranking AS
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
                    max_rank,
                    tier_1_size,
                    tier_2_size
                FROM catalog.credit_product_popularity_ranking_config
                WHERE is_active = true
            ),

            -- ---------------------------------------------------------------
            -- 2. Pull every eligible order line from credit-enabled customers.
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
                JOIN shopwired.customers c
                    ON c.external_id = o.customer_id
                WHERE c.is_credit_enabled = true
                  AND o.lifecycle_status NOT IN ('cancelled', 'refunded')
            ),

            -- ---------------------------------------------------------------
            -- 3. Aggregate both periods in a single pass. Windows are
            --    OVERLAPPING — recent (3mo) is a subset of main (12mo).
            --    Recent sales boost both scores; recency weight is a bonus.
            -- ---------------------------------------------------------------
            period_totals AS (
                SELECT
                    rl.parent_external_id,

                    SUM(CASE WHEN rl.order_placed_at >= NOW() - params.main_period
                             THEN rl.quantity ELSE 0 END) AS main_qty,

                    SUM(CASE WHEN rl.order_placed_at >= NOW() - params.main_period
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
            --    has_any_sales partitions credit-sellers from non-credit-sellers.
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
            ),

            -- ---------------------------------------------------------------
            -- 8. Rank products by final_score within their sales partition.
            --    Computed once here so the credit_tier CASE doesn't have to
            --    re-evaluate ROW_NUMBER per branch.
            -- ---------------------------------------------------------------
            ranked_products AS (
                SELECT
                    fs.*,
                    ROW_NUMBER() OVER (PARTITION BY fs.has_any_sales ORDER BY fs.final_score DESC) AS sales_rank
                FROM final_scores fs
            )

            -- ---------------------------------------------------------------
            -- 9. Final SELECT — all catalog products.
            --    credit_tier boundaries count only within the credit-sales
            --    partition; non-sellers → NULL.
            -- ---------------------------------------------------------------
            SELECT
                params.algorithm_version,
                rp.parent_external_id,
                rp.sku,
                rp.title,
                rp.is_active,
                ((params.max_rank + 1) - ROUND(rp.final_score)::int) AS calculated_sort_order,
                rp.current_sort_order,
                rp.main_qty,
                ROUND(rp.main_turnover, 2)           AS main_turnover,
                rp.recent_qty,
                ROUND(rp.recent_turnover, 2)         AS recent_turnover,
                ROUND(rp.main_qty_rank, 2)           AS main_qty_rank,
                ROUND(rp.main_turnover_rank, 2)      AS main_turnover_rank,
                ROUND(rp.recent_qty_rank, 2)         AS recent_qty_rank,
                ROUND(rp.recent_turnover_rank, 2)    AS recent_turnover_rank,
                ROUND(rp.main_score, 2)              AS main_score,
                ROUND(rp.recent_score, 2)            AS recent_score,
                ROUND(rp.final_score, 2)             AS final_score,
                ROUND(rp.recent_score - rp.main_score, 2) AS trend,
                CASE
                    WHEN NOT rp.has_any_sales                THEN NULL
                    WHEN rp.sales_rank <= params.tier_1_size THEN 'Credit - Tier 1'
                    WHEN rp.sales_rank <= params.tier_2_size THEN 'Credit - Tier 2'
                    ELSE 'Credit - Tier 3'
                END AS credit_tier
            FROM ranked_products rp
            CROSS JOIN params
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS catalog.credit_product_popularity_ranking');
    }
};
