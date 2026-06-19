<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION checkout.basket_recovery_matches(
                scope_window_days int DEFAULT 4,
                only_needs_update bool DEFAULT true
            )
            RETURNS TABLE (
                basket_total numeric,
                delivery_date text,
                gift_note text,
                vat_relief jsonb,
                snapshot_created_at timestamptz,
                order_number text,
                match_count bigint,
                multiple_orders_placed_within_timeframe boolean,
                order_missing_vat_relief boolean,
                order_missing_gift_note boolean,
                order_missing_delivery_date boolean,
                has_missing_data boolean
            )
            LANGUAGE sql STABLE
            AS $fn$
                WITH
                latest_snapshot AS (
                    SELECT DISTINCT ON (bs.ip_address)
                           bs.ip_address,
                           bs.basket_total,
                           bs.delivery_date,
                           bs.gift_note,
                           bs.vat_relief,
                           bs.created_at
                    FROM checkout.basket_snapshots bs
                    WHERE (bs.delivery_date IS NOT NULL
                        OR bs.gift_note     IS NOT NULL
                        OR (bs.vat_relief->>'eligible')::boolean IS TRUE)
                      AND bs.created_at >= now() - MAKE_INTERVAL(days => scope_window_days)
                    ORDER BY bs.ip_address, bs.created_at DESC
                ),

                orders_dedup AS MATERIALIZED (
                    SELECT DISTINCT ON (reference)
                           reference,
                           total,
                           order_placed_at,
                           comments,
                           delivery_date,
                           ip
                    FROM (
                        SELECT o.reference,
                               o.total,
                               o.order_placed_at,
                               o.comments,
                               o.delivery_date,
                               o.external_id,
                               CASE
                                   WHEN trim(split_part(o.customer_device_info->>'ipAddress', ',', 1))
                                            ~ '^(\d{1,3}\.){3}\d{1,3}$|^[0-9a-fA-F:]+$'
                                   THEN trim(split_part(o.customer_device_info->>'ipAddress', ',', 1))::inet
                               END AS ip
                        FROM shopwired.orders o
                        WHERE o.order_placed_at >= now() - MAKE_INTERVAL(days => scope_window_days)
                          AND o.order_placed_at <= now() + INTERVAL '30 minutes'
                    ) o
                    ORDER BY reference,
                             (ip IS NULL),
                             external_id DESC
                )

                SELECT matched.*,
                       (   matched.order_missing_vat_relief
                        OR matched.order_missing_gift_note
                        OR matched.order_missing_delivery_date) AS has_missing_data
                FROM (
                    SELECT s.basket_total,
                           s.delivery_date,
                           s.gift_note,
                           s.vat_relief,
                           s.created_at        AS snapshot_created_at,
                           m.order_number,
                           m.match_count,
                           (m.match_count > 1) AS multiple_orders_placed_within_timeframe,

                           ((s.vat_relief->>'eligible')::boolean IS TRUE
                                AND NOT COALESCE(m.any_order_has_vat_relief, false))    AS order_missing_vat_relief,
                           (NULLIF(s.gift_note, '') IS NOT NULL
                                AND NOT COALESCE(m.any_order_has_gift, false))          AS order_missing_gift_note,
                           (s.delivery_date IS NOT NULL
                                AND NOT COALESCE(m.any_order_has_delivery_date, false)) AS order_missing_delivery_date
                    FROM latest_snapshot s
                    JOIN LATERAL (
                        SELECT string_agg(o.reference::text, ', ' ORDER BY o.order_placed_at) AS order_number,
                               count(*)                              AS match_count,
                               bool_or(o.comments ILIKE '%vat relief%') AS any_order_has_vat_relief,
                               bool_or(o.comments ILIKE '%gift%')       AS any_order_has_gift,
                               bool_or(o.delivery_date IS NOT NULL)     AS any_order_has_delivery_date
                        FROM orders_dedup o
                        WHERE o.ip              =  s.ip_address
                          AND o.total           =  s.basket_total
                          AND o.order_placed_at >= s.created_at
                          AND o.order_placed_at <= s.created_at + INTERVAL '30 minutes'
                    ) m ON m.match_count > 0
                ) matched
                WHERE NOT only_needs_update
                   OR matched.order_missing_vat_relief
                   OR matched.order_missing_gift_note
                   OR matched.order_missing_delivery_date
                ORDER BY matched.snapshot_created_at DESC;
            $fn$;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS checkout.basket_recovery_matches');
    }
};
