-- COR-297: runs the SQL editor copy of orders-profile-export.sql against hand-built fixture orders inside one rolled-back transaction. Local database only.
-- bash database/seed-profile/orders-profile-editor-copy.sh
-- psql -X "postgresql://postgres:postgres@127.0.0.1:54322/postgres" -v ON_ERROR_STOP=1 -At -f database/seed-profile/orders-profile-fixture.sql | jq -e -f database/seed-profile/orders-profile-output-check.jq
--
-- Fixture (reference: external_id, payment, status, destination, lines as tuple x qty). A = (10.000000, 2.000000, 20), Z = (5.000000, 0.000000, 0).
--   r1: 101 card UK, A x1.   r2: 102 card UK, A x1.   r3: 103 card UK, A x2.
--   r4: 104 card UK, A x1, VAT-exempt (tax_value NULL), total lowered to 0, refund 10.00 = baseline 10 + 0 + 0: refunded.
--   r5: 105 paypal Ireland, A x1, total lowered to 7, refund 5.00 < baseline 12: part-refunded (against total it would read as refunded).
--   r6: 106 cancelled original with A x3, edited into 107 card UK with A x1 + Z x1; 106's lines are never counted.
--   r7: 108 admin France, cancelled, VAT relief, no lines.
--   r8: 109 placed this month and r9: 110 placed 13 months ago: outside the window, counted nowhere.
-- Expected: raw_rows 8, deduplicated_references 7, lines 7.
--   payment_method rows card 5, suppressed 2. lines_per_order rows 1 -> 5, suppressed 2 (0 -> 1, 2 -> 1).
--   qty rows qty 1 (lines 6, orders 5), suppressed 1. price_tuples rows A (lines 6, orders 6), suppressed 1.
--   destination_class rows uk 5, suppressed 2. Each share: orders 1, share 0.142857.
\set QUIET on
BEGIN;

DELETE FROM shopwired.order_refunds;
DELETE FROM shopwired.order_discounts;
DELETE FROM shopwired.order_admin_comments;
DELETE FROM shopwired.order_products;
DELETE FROM shopwired.orders;

INSERT INTO shopwired.orders (
    external_id, reference, total, sub_total_net, shipping_total_net, tax_value, lifecycle_status, delivery_country,
    has_vat_relief, payment_method, order_placed_at,
    status_name, status_type, customer_id, customer_type,
    billing_name, billing_email, billing_address_line1, billing_city, billing_postcode, billing_country,
    delivery_name, delivery_email, delivery_address_line1, delivery_city, delivery_postcode,
    created_at, updated_at
)
SELECT v.external_id, v.reference, v.total, 10.00, 0, CASE WHEN v.external_id = 104 THEN NULL ELSE 2.00 END, v.lifecycle_status, v.delivery_country,
    v.has_vat_relief, v.payment_method, date_trunc('month', now()) + v.placed,
    'Fixture', 'fixture', 1, 1,
    'Fixture', 'fixture@example.test', 'Fixture', 'Fixture', 'FX1 1FX', 'United Kingdom',
    'Fixture', 'fixture@example.test', 'Fixture', 'Fixture', 'FX1 1FX',
    now(), now()
FROM (VALUES
    (101, 1, 12.00, 'active', 'United Kingdom', false, 'card', interval '-1 month'),
    (102, 2, 12.00, 'active', 'United Kingdom', false, 'card', interval '-2 months'),
    (103, 3, 24.00, 'active', 'United Kingdom', false, 'card', interval '-11 months'),
    (104, 4, 0.00, 'refunded', 'United Kingdom', false, 'card', interval '-12 months'),
    (105, 5, 7.00, 'active', ' ireland ', false, 'paypal', interval '-1 day'),
    (106, 6, 36.00, 'cancelled', 'United Kingdom', false, 'card', interval '-3 months'),
    (107, 6, 17.00, 'active', 'United Kingdom', false, 'card', interval '-3 months'),
    (108, 7, 0.00, 'cancelled', 'France', true, 'admin', interval '-4 months'),
    (109, 8, 12.00, 'active', 'United Kingdom', false, 'card', interval '1 hour'),
    (110, 9, 12.00, 'active', 'United Kingdom', false, 'card', interval '-13 months')
) v (external_id, reference, total, lifecycle_status, delivery_country, has_vat_relief, payment_method, placed);

INSERT INTO shopwired.order_products (
    order_id, order_external_id, external_id, title, sku, price, price_vat, total, total_vat, original_price, quantity, vat_rate, created_at, updated_at
)
SELECT o.id, v.order_external_id, v.external_id, 'Fixture', 'FIXTURE', v.price, v.price_vat, 0, 0, 0, v.quantity, v.vat_rate, now(), now()
FROM (VALUES
    (101, 2001, 10.000000, 2.000000, 1, 20.000000),
    (102, 2002, 10.000000, 2.000000, 1, 20.000000),
    (103, 2003, 10.000000, 2.000000, 2, 20.000000),
    (104, 2004, 10.000000, 2.000000, 1, 20.000000),
    (105, 2005, 10.000000, 2.000000, 1, 20.000000),
    (106, 2006, 10.000000, 2.000000, 3, 20.000000),
    (107, 2007, 10.000000, 2.000000, 1, 20.000000),
    (107, 2008, 5.000000, 0.000000, 1, 0.000000),
    (109, 2009, 10.000000, 2.000000, 1, 20.000000),
    (110, 2010, 10.000000, 2.000000, 1, 20.000000)
) v (order_external_id, external_id, price, price_vat, quantity, vat_rate)
JOIN shopwired.orders o ON o.external_id = v.order_external_id;

INSERT INTO shopwired.order_refunds (order_id, order_external_id, external_id, name, value, created_at, updated_at)
SELECT o.id, o.external_id, 3000 + o.external_id, 'Fixture', v.value, now(), now()
FROM (VALUES (104, 10.00), (105, 5.00)) v (order_external_id, value)
JOIN shopwired.orders o ON o.external_id = v.order_external_id;

\ir ../../examples/seed-profile/orders-profile-editor.sql

ROLLBACK;
