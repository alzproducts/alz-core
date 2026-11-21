-- COR-295 VAT replay: replays the QuickBooks VAT that M1 and the Zap as built would produce, against ShopWired's tax_value.
-- One read-only SELECT returning one jsonb value: counts, signed pence deltas and anonymised shapes only.
-- It returns no names, emails, addresses, countries, order references, external IDs, prices, costs or margins.
-- Cohort: winning orders of orders_deduplicated with at least one line, none of whose same-reference orders has a refund.
-- Each excluded order counts under its first matching reason; null_original_shipping applies to zap_as_built only.
-- Run command and method: docs/reports/money-vat-flow-review.md section 3. psql must pass -v sql_sha256=<sha256 of this file>.
WITH src_orders AS (
    SELECT d.external_id AS order_key,
        d.tax_value,
        d.sub_total_net,
        d.shipping_total_net,
        d.original_shipping_total_net,
        d.shipping_vat_rate,
        d.line_item_vat_calculation,
        upper(trim(d.delivery_country)) IN ('IRELAND', 'IE') AS ireland,
        d.shipping_total_net <> 0 OR coalesce(d.original_shipping_total_net, 0) <> 0 AS has_shipping
    FROM shopwired.orders_deduplicated d
    WHERE NOT EXISTS (
        SELECT 1
        FROM shopwired.order_refunds r
        JOIN shopwired.orders o ON o.id = r.order_id
        WHERE o.reference = d.reference
    )
),
src_lines AS (
    SELECT l.order_external_id AS order_key, l.price, l.price_vat, l.quantity AS qty, trunc(l.vat_rate)::int AS rate
    FROM shopwired.order_products_resolved l
    JOIN src_orders o ON o.order_key = l.order_external_id
),
src_line_facts AS (
    SELECT order_key,
        count(1) AS line_count,
        bool_or(qty > 1) AS multi_qty,
        bool_or(rate = 0) AS has_zero,
        bool_or(rate NOT IN (0, 20)) AS unmapped,
        array_to_string(array_agg(DISTINCT rate ORDER BY rate), ',') AS rates
    FROM src_lines
    GROUP BY order_key
),
src_cohort AS (
    SELECT o.order_key,
        o.tax_value,
        o.sub_total_net,
        o.shipping_total_net,
        o.original_shipping_total_net,
        coalesce(trunc(o.shipping_vat_rate)::int, 0) AS ship_rate,
        CASE
            WHEN o.tax_value IS NULL THEN 'null_tax_value'
            WHEN o.has_shipping AND o.shipping_vat_rate IS NULL THEN 'null_shipping_rate'
            WHEN f.unmapped OR (o.has_shipping AND trunc(o.shipping_vat_rate)::int NOT IN (0, 20)) THEN 'unmapped_rate'
            WHEN o.ireland THEN 'ireland_remap'
        END AS excluded_reason,
        concat_ws(
            ';',
            'lines=' || CASE WHEN f.line_count = 1 THEN '1' WHEN f.line_count = 2 THEN '2' WHEN f.line_count <= 5 THEN '3-5' ELSE '6+' END,
            'qty=' || CASE WHEN f.multi_qty THEN 't' ELSE 'f' END,
            'rates=' || f.rates,
            'ship=' || CASE
                WHEN o.original_shipping_total_net IS NULL THEN 'unknown'
                WHEN o.shipping_total_net < o.original_shipping_total_net THEN 't'
                ELSE 'f'
            END,
            'zero=' || CASE WHEN f.has_zero THEN 't' ELSE 'f' END,
            'calc=' || CASE WHEN o.line_item_vat_calculation THEN 'true' ELSE 'false' END
        ) AS shape
    FROM src_orders o
    JOIN src_line_facts f ON f.order_key = o.order_key
),
-- M1 (COR-295/D16, D8, D10) in numeric: round(numeric, n) rounds half away from zero.
m1_items AS (
    SELECT l.order_key,
        l.rate,
        round(round((l.price + l.price_vat) / CASE WHEN l.rate < 1 THEN 1 ELSE (100 + l.rate) / 100.0 END, 7) * l.qty, 2) AS amount,
        true AS product
    FROM src_lines l
    JOIN src_cohort c ON c.order_key = l.order_key AND c.excluded_reason IS NULL
    UNION ALL
    SELECT order_key, ship_rate, round(shipping_total_net, 2), false
    FROM src_cohort
    WHERE excluded_reason IS NULL
),
m1_orders AS (
    SELECT c.order_key, c.tax_value, c.sub_total_net, c.shape, v.vat_per_line, v.lines_net, r.vat_per_rate
    FROM src_cohort c
    JOIN (
        SELECT order_key, sum(round(amount * rate / 100.0, 2)) AS vat_per_line, sum(amount) FILTER (WHERE product) AS lines_net
        FROM m1_items
        GROUP BY order_key
    ) v ON v.order_key = c.order_key
    JOIN (
        SELECT order_key, sum(round(rate_net * rate / 100.0, 2)) AS vat_per_rate
        FROM (SELECT order_key, rate, sum(amount) AS rate_net FROM m1_items GROUP BY order_key, rate) g
        GROUP BY order_key
    ) r ON r.order_key = c.order_key
),
m1_results AS (
    SELECT order_key,
        lines_net <> sub_total_net AS base,
        round((vat_per_line - tax_value) * 100)::int AS delta_line,
        round((vat_per_rate - tax_value) * 100)::int AS delta_rate,
        shape || ';base=' || CASE WHEN lines_net <> sub_total_net THEN 't' ELSE 'f' END AS shape_key
    FROM m1_orders
),
-- Zap as built in double precision. intentional: floor(x + 0.5) copies JS Math.round; round(double precision) rounds ties to even.
zap_lines AS (
    SELECT l.order_key,
        l.rate,
        l.qty,
        n.net7,
        floor(n.net7 * l.qty * 100 + sign(n.net7 * l.qty) * 0.0001::float8 + 0.5::float8) / 100 AS amount
    FROM src_lines l
    JOIN src_cohort c ON c.order_key = l.order_key AND c.excluded_reason IS NULL AND c.original_shipping_total_net IS NOT NULL
    CROSS JOIN LATERAL (
        SELECT floor(g.unit * 10000000 + sign(g.unit) * 0.0001::float8 + 0.5::float8) / 10000000 AS net7
        FROM (
            SELECT (l.price::float8 + l.price_vat::float8) / CASE WHEN l.rate < 1 THEN 1 ELSE (100 + l.rate)::float8 / 100 END AS unit
        ) g
    ) n
),
-- intentional: float8 to numeric keeps 15 significant digits, the Zap's toPrecision(15) snap.
zap_line_checks AS (
    SELECT z.order_key, z.rate, z.amount::numeric AS amount, sign(s.snap) * floor(abs(s.snap) * 100 + 0.5) / 100 <> z.amount::numeric AS fallback
    FROM zap_lines z
    CROSS JOIN LATERAL (SELECT (z.qty * z.net7)::numeric AS snap) s
),
zap_items AS (
    SELECT order_key, rate, amount, fallback FROM zap_line_checks
    UNION ALL
    SELECT c.order_key, c.ship_rate, sign(s.snap) * floor(abs(s.snap) * 100 + 0.5) / 100, false
    FROM src_cohort c
    CROSS JOIN LATERAL (SELECT c.original_shipping_total_net::float8::numeric AS snap) s
    WHERE c.excluded_reason IS NULL AND c.original_shipping_total_net IS NOT NULL
),
zap_orders AS (
    SELECT c.order_key, c.tax_value, c.shape, v.vat_per_line, v.fallback_lines, r.vat_per_rate
    FROM src_cohort c
    JOIN (
        SELECT order_key,
            sum(sign(amount * rate) * floor(abs(amount * rate) + 0.5) / 100) AS vat_per_line,
            count(1) FILTER (WHERE fallback) AS fallback_lines
        FROM zap_items
        GROUP BY order_key
    ) v ON v.order_key = c.order_key
    JOIN (
        SELECT order_key, sum(sign(rate_net * rate) * floor(abs(rate_net * rate) + 0.5) / 100) AS vat_per_rate
        FROM (SELECT order_key, rate, sum(amount) AS rate_net FROM zap_items GROUP BY order_key, rate) g
        GROUP BY order_key
    ) r ON r.order_key = c.order_key
),
zap_results AS (
    SELECT z.order_key,
        z.fallback_lines,
        (sign(z.vat_per_line - z.tax_value) * floor(abs(z.vat_per_line - z.tax_value) * 100 + 0.5))::int AS delta_line,
        (sign(z.vat_per_rate - z.tax_value) * floor(abs(z.vat_per_rate - z.tax_value) * 100 + 0.5))::int AS delta_rate,
        m.shape_key || ';fb=' || CASE WHEN z.fallback_lines > 0 THEN 't' ELSE 'f' END AS shape_key
    FROM zap_orders z
    JOIN m1_results m ON m.order_key = z.order_key
),
out_rows AS (
    SELECT 'm1' AS variant, 'per_line' AS mode, delta_line AS delta_pence, shape_key, 0 AS fallback_lines FROM m1_results
    UNION ALL
    SELECT 'm1', 'per_rate', delta_rate, shape_key, 0 FROM m1_results
    UNION ALL
    SELECT 'zap_as_built', 'per_line', delta_line, shape_key, fallback_lines FROM zap_results
    UNION ALL
    SELECT 'zap_as_built', 'per_rate', delta_rate, shape_key, fallback_lines FROM zap_results
),
out_grid AS (
    SELECT v.variant, m.mode
    FROM (VALUES ('m1'), ('zap_as_built')) v (variant)
    CROSS JOIN (VALUES ('per_line'), ('per_rate')) m (mode)
),
out_counts AS (
    SELECT variant,
        mode,
        count(1) AS orders,
        count(1) FILTER (WHERE delta_pence <> 0) AS mismatched,
        sum(fallback_lines) AS fallback_lines,
        count(1) FILTER (WHERE fallback_lines > 0) AS fallback_orders
    FROM out_rows
    GROUP BY variant, mode
),
out_deltas AS (
    SELECT variant, mode, jsonb_object_agg(delta_pence::text, n) AS deltas
    FROM (SELECT variant, mode, delta_pence, count(1) AS n FROM out_rows WHERE delta_pence <> 0 GROUP BY variant, mode, delta_pence) g
    GROUP BY variant, mode
),
out_shapes AS (
    SELECT variant, mode, jsonb_object_agg(shape_key, n) AS shapes
    FROM (SELECT variant, mode, shape_key, count(1) AS n FROM out_rows WHERE delta_pence <> 0 GROUP BY variant, mode, shape_key) g
    GROUP BY variant, mode
),
out_modes AS (
    SELECT g.variant,
        g.mode,
        jsonb_build_object(
            'orders', coalesce(c.orders, 0),
            'mismatched', coalesce(c.mismatched, 0),
            'delta_pence', coalesce(d.deltas, '{}'::jsonb),
            'shapes', coalesce(s.shapes, '{}'::jsonb)
        ) || CASE
            WHEN g.variant = 'zap_as_built' THEN jsonb_build_object('fallback_lines', coalesce(c.fallback_lines, 0), 'fallback_orders', coalesce(c.fallback_orders, 0))
            ELSE '{}'::jsonb
        END AS body
    FROM out_grid g
    LEFT JOIN out_counts c ON c.variant = g.variant AND c.mode = g.mode
    LEFT JOIN out_deltas d ON d.variant = g.variant AND d.mode = g.mode
    LEFT JOIN out_shapes s ON s.variant = g.variant AND s.mode = g.mode
),
out_excluded AS (
    SELECT jsonb_object_agg(r.reason, coalesce(n.n, 0)) AS excluded
    FROM (VALUES ('null_tax_value'), ('null_shipping_rate'), ('unmapped_rate'), ('ireland_remap'), ('null_original_shipping')) r (reason)
    LEFT JOIN (
        SELECT excluded_reason AS reason, count(1) AS n FROM src_cohort WHERE excluded_reason IS NOT NULL GROUP BY excluded_reason
        UNION ALL
        SELECT 'null_original_shipping', count(1) FROM src_cohort WHERE excluded_reason IS NULL AND original_shipping_total_net IS NULL
    ) n ON n.reason = r.reason
)
SELECT jsonb_build_object(
    'exported_at', to_char(now() AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"'),
    'sql_sha256', :'sql_sha256',
    'excluded', (SELECT excluded FROM out_excluded),
    'baseline', (SELECT count(1) FILTER (WHERE base) FROM m1_results),
    'm1', (SELECT jsonb_object_agg(mode, body) FROM out_modes WHERE variant = 'm1'),
    'zap_as_built', (SELECT jsonb_object_agg(mode, body) FROM out_modes WHERE variant = 'zap_as_built')
);
