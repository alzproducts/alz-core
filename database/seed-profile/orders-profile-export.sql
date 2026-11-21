-- COR-297 orders seed profile export.
-- One read-only SELECT returning one jsonb value: counts, shares, distributions and (price, price_vat, vat_rate) tuples.
-- It returns no names, emails, addresses, references, external IDs, customer IDs, titles, SKUs, costs or order dates.
-- Window: orders placed in the last 12 complete calendar months. Shape distributions and the cancelled share read
-- orders_deduplicated; the edited-order share reads shopwired.orders. Every share's denominator is deduplicated references.
-- Each multi-row distribution drops rows seen on fewer than 5 distinct orders and adds them to its `suppressed` total
-- (orders for order distributions, lines for qty and price_tuples). Scalar shares are never suppressed.
-- The output holds real price tuples: it stays in gitignored examples/seed-profile/ and is never committed (ADR-0013).
--
-- A human runs this against prod with a read-only service; credentials come from PGSERVICE or ~/.pgpass:
--   mkdir -p examples/seed-profile
--   PGOPTIONS='-c default_transaction_read_only=on -c statement_timeout=120s' PGSERVICE=<prod read-only service> psql -X -v ON_ERROR_STOP=1 -v sql_sha256="$(shasum -a 256 database/seed-profile/orders-profile-export.sql | cut -c1-64)" -At -f database/seed-profile/orders-profile-export.sql > examples/seed-profile/orders-profile.json
--   jq -e -f database/seed-profile/orders-profile-output-check.jq examples/seed-profile/orders-profile.json
-- No one reads the output until the jq check exits 0. Local fixture run: orders-profile-fixture.sql.
WITH bounds AS (
    SELECT date_trunc('month', now()) - interval '12 months' AS window_start, date_trunc('month', now()) AS window_end
),
raw_orders AS (
    SELECT o.id
    FROM shopwired.orders o, bounds b
    WHERE o.order_placed_at >= b.window_start AND o.order_placed_at < b.window_end
),
dedup AS (
    SELECT d.id,
        coalesce(d.payment_method, '<null>') AS payment_method,
        d.lifecycle_status,
        d.has_vat_relief,
        d.sub_total_net + d.shipping_total_net + d.tax_value AS refund_baseline,
        CASE
            WHEN upper(btrim(d.delivery_country)) IN ('UNITED KINGDOM', 'UK', 'GB', 'GREAT BRITAIN', 'ENGLAND', 'SCOTLAND', 'WALES', 'NORTHERN IRELAND') THEN 'uk'
            WHEN upper(btrim(d.delivery_country)) IN ('IRELAND', 'IE', 'REPUBLIC OF IRELAND', 'EIRE') THEN 'ireland'
            WHEN upper(btrim(d.delivery_country)) IN (
                'AUSTRIA', 'AT', 'BELGIUM', 'BE', 'BULGARIA', 'BG', 'CROATIA', 'HR', 'CYPRUS', 'CY', 'CZECHIA', 'CZECH REPUBLIC', 'CZ',
                'DENMARK', 'DK', 'ESTONIA', 'EE', 'FINLAND', 'FI', 'FRANCE', 'FR', 'GERMANY', 'DE', 'GREECE', 'GR', 'EL',
                'HUNGARY', 'HU', 'ITALY', 'IT', 'LATVIA', 'LV', 'LITHUANIA', 'LT', 'LUXEMBOURG', 'LU', 'MALTA', 'MT',
                'NETHERLANDS', 'THE NETHERLANDS', 'NL', 'POLAND', 'PL', 'PORTUGAL', 'PT', 'ROMANIA', 'RO', 'SLOVAKIA', 'SK',
                'SLOVENIA', 'SI', 'SPAIN', 'ES', 'SWEDEN', 'SE'
            ) THEN 'rest_of_eu'
            ELSE 'other'
        END AS destination_class
    FROM shopwired.orders_deduplicated d, bounds b
    WHERE d.order_placed_at >= b.window_start AND d.order_placed_at < b.window_end
),
lines AS (
    SELECT l.order_id, l.price, l.price_vat, l.vat_rate, l.quantity
    FROM shopwired.order_products l
    JOIN dedup o ON o.id = l.order_id
),
refunds AS (
    SELECT o.id AS order_id, o.refund_baseline, sum(r.value) AS refunded
    FROM shopwired.order_refunds r
    JOIN dedup o ON o.id = r.order_id
    GROUP BY o.id, o.refund_baseline
),
edited AS (
    SELECT count(1) AS n
    FROM (
        SELECT 1
        FROM shopwired.orders o, bounds b
        WHERE o.order_placed_at >= b.window_start AND o.order_placed_at < b.window_end
        GROUP BY o.reference
        HAVING count(DISTINCT o.external_id) > 1
    ) e
),
refs AS (
    SELECT count(1) AS n FROM dedup
),
dist_payment AS (
    SELECT payment_method AS k, count(1) AS orders FROM dedup GROUP BY 1
),
dist_lines_per_order AS (
    SELECT line_count AS k, count(1) AS orders
    FROM (SELECT o.id, count(l.order_id) AS line_count FROM dedup o LEFT JOIN lines l ON l.order_id = o.id GROUP BY o.id) x
    GROUP BY 1
),
dist_qty AS (
    SELECT quantity AS k, count(1) AS lines, count(DISTINCT order_id) AS orders FROM lines GROUP BY 1
),
dist_tuples AS (
    SELECT price, price_vat, vat_rate, count(1) AS lines, count(DISTINCT order_id) AS orders FROM lines GROUP BY 1, 2, 3
),
dist_destination AS (
    SELECT destination_class AS k, count(1) AS orders FROM dedup GROUP BY 1
),
share_counts AS (
    SELECT
        (SELECT count(DISTINCT order_id) FROM lines WHERE vat_rate = 0) AS zero_rated_line,
        (SELECT count(1) FROM dedup WHERE has_vat_relief) AS vat_relief,
        (SELECT count(1) FROM refunds WHERE refunded >= refund_baseline) AS refunded,
        (SELECT count(1) FROM refunds WHERE refunded > 0 AND refunded < refund_baseline) AS part_refunded,
        (SELECT count(1) FROM dedup WHERE lifecycle_status = 'cancelled') AS cancelled,
        (SELECT n FROM edited) AS edited
)
SELECT jsonb_build_object(
    'exported_at', to_char(now() AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"'),
    'sql_sha256', :'sql_sha256',
    'window', (
        SELECT jsonb_build_object(
            'start', to_char(window_start AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"'),
            'end', to_char(window_end AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"')
        )
        FROM bounds
    ),
    'counts', jsonb_build_object(
        'raw_rows', (SELECT count(1) FROM raw_orders),
        'deduplicated_references', (SELECT n FROM refs),
        'lines', (SELECT count(1) FROM lines)
    ),
    'distributions', jsonb_build_object(
        'payment_method', (
            SELECT jsonb_build_object(
                'rows', coalesce(jsonb_agg(jsonb_build_object('payment_method', k, 'orders', orders) ORDER BY k) FILTER (WHERE orders >= 5), '[]'::jsonb),
                'suppressed', coalesce(sum(orders) FILTER (WHERE orders < 5), 0)
            )
            FROM dist_payment
        ),
        'lines_per_order', (
            SELECT jsonb_build_object(
                'rows', coalesce(jsonb_agg(jsonb_build_object('line_count', k, 'orders', orders) ORDER BY k) FILTER (WHERE orders >= 5), '[]'::jsonb),
                'suppressed', coalesce(sum(orders) FILTER (WHERE orders < 5), 0)
            )
            FROM dist_lines_per_order
        ),
        'qty', (
            SELECT jsonb_build_object(
                'rows', coalesce(jsonb_agg(jsonb_build_object('qty', k, 'lines', lines, 'orders', orders) ORDER BY k) FILTER (WHERE orders >= 5), '[]'::jsonb),
                'suppressed', coalesce(sum(lines) FILTER (WHERE orders < 5), 0)
            )
            FROM dist_qty
        ),
        'price_tuples', (
            SELECT jsonb_build_object(
                'rows', coalesce(
                    jsonb_agg(
                        jsonb_build_object('price', price, 'price_vat', price_vat, 'vat_rate', vat_rate, 'lines', lines, 'orders', orders)
                        ORDER BY vat_rate, price, price_vat
                    ) FILTER (WHERE orders >= 5),
                    '[]'::jsonb
                ),
                'suppressed', coalesce(sum(lines) FILTER (WHERE orders < 5), 0)
            )
            FROM dist_tuples
        ),
        'destination_class', (
            SELECT jsonb_build_object(
                'rows', coalesce(jsonb_agg(jsonb_build_object('destination_class', k, 'orders', orders) ORDER BY k) FILTER (WHERE orders >= 5), '[]'::jsonb),
                'suppressed', coalesce(sum(orders) FILTER (WHERE orders < 5), 0)
            )
            FROM dist_destination
        )
    ),
    'shares', (
        SELECT jsonb_build_object(
            'zero_rated_line', jsonb_build_object('orders', s.zero_rated_line, 'share', round(s.zero_rated_line::numeric / nullif(r.n, 0), 6)),
            'vat_relief', jsonb_build_object('orders', s.vat_relief, 'share', round(s.vat_relief::numeric / nullif(r.n, 0), 6)),
            'refunded', jsonb_build_object('orders', s.refunded, 'share', round(s.refunded::numeric / nullif(r.n, 0), 6)),
            'part_refunded', jsonb_build_object('orders', s.part_refunded, 'share', round(s.part_refunded::numeric / nullif(r.n, 0), 6)),
            'cancelled', jsonb_build_object('orders', s.cancelled, 'share', round(s.cancelled::numeric / nullif(r.n, 0), 6)),
            'edited', jsonb_build_object('orders', s.edited, 'share', round(s.edited::numeric / nullif(r.n, 0), 6))
        )
        FROM share_counts s, refs r
    )
);
