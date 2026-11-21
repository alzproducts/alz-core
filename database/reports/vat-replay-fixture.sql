-- COR-295: runs vat-replay.sql against hand-computed fixture orders inside one rolled-back transaction. Local database only.
-- psql -X -v ON_ERROR_STOP=1 -At -f database/reports/vat-replay-fixture.sql | jq -e -f database/reports/vat-replay-output-check.jq
--
-- Expected output (hand computation, per order external_id):
--   1001 two lines of gross 1.481999 at 20%: net7 1.2349992; m1 amounts 1.23 + 1.23, Zap amounts 1.24 + 1.24 and both lines fall back.
--        tax_value 0.50: m1 per_line 0.50 (delta 0), m1 per_rate 0.49 (delta -1); Zap per_line and per_rate 0.50 (delta 0).
--   1002 gross 1.005 at 0%: the fallback snap 1.005 rounds half away to 1.01, as precise_round does (half-even gives 1.00); no fallback.
--   1003 refunded; 1004 (cancelled, refunded) shares reference 4 with winner 1005: both excluded from the cohort, counted nowhere.
--   1006 (cancelled) shares reference 5 with winner 1007; 1006's lines would add 1.00 VAT if joined by reference. 1007 delta 0.
--   1008 null_tax_value, 1009 null_shipping_rate, 1010 unmapped_rate (5%), 1011 ireland_remap: one excluded order each.
--   1012 NULL original shipping: m1 only, VAT 2.00 vs tax_value 2.01 (delta -1, ship=unknown); null_original_shipping 1.
--   1013 shipping net 4.125 at 20%: both variants post 4.13 (half away), VAT 2.00 + 0.83 = 2.83 = tax_value (delta 0).
-- Totals: m1 orders 5 (per_line mismatched 1, per_rate 2); zap_as_built orders 4, mismatched 0, fallback_lines 2, fallback_orders 1;
--   baseline 0; every excluded reason 1.
\set QUIET on
\set sql_sha256 0000000000000000000000000000000000000000000000000000000000000000
BEGIN;

DELETE FROM shopwired.order_refunds;
DELETE FROM shopwired.order_discounts;
DELETE FROM shopwired.order_admin_comments;
DELETE FROM shopwired.order_products;
DELETE FROM shopwired.orders;

INSERT INTO shopwired.orders (
    external_id, reference, total, sub_total_net, shipping_total_net, original_shipping_total_net, shipping_vat_rate, tax_value,
    lifecycle_status, delivery_country, line_item_vat_calculation,
    status_name, status_type, customer_id, customer_type,
    billing_name, billing_email, billing_address_line1, billing_city, billing_postcode, billing_country,
    delivery_name, delivery_email, delivery_address_line1, delivery_city, delivery_postcode,
    payment_method, order_placed_at, created_at, updated_at
)
SELECT v.external_id, v.reference, 0, v.sub_total_net, v.shipping_total_net, v.original_shipping_total_net, v.shipping_vat_rate, v.tax_value,
    v.lifecycle_status, v.delivery_country, true,
    'Fixture', 'fixture', 1, 1,
    'Fixture', 'fixture@example.test', 'Fixture', 'Fixture', 'FX1 1FX', 'United Kingdom',
    'Fixture', 'fixture@example.test', 'Fixture', 'Fixture', 'FX1 1FX',
    'fixture', now(), now(), now()
FROM (VALUES
    (1001, 1, 2.46, 0, 0, 20, 0.50, 'active', 'United Kingdom'),
    (1002, 2, 1.01, 0, 0, 20, 0.00, 'active', 'United Kingdom'),
    (1003, 3, 10.00, 0, 0, 20, 2.00, 'active', 'United Kingdom'),
    (1004, 4, 10.00, 0, 0, 20, 2.00, 'cancelled', 'United Kingdom'),
    (1005, 4, 10.00, 0, 0, 20, 2.00, 'active', 'United Kingdom'),
    (1006, 5, 5.00, 0, 0, 20, 1.00, 'cancelled', 'United Kingdom'),
    (1007, 5, 10.00, 0, 0, 20, 2.00, 'active', 'United Kingdom'),
    (1008, 6, 10.00, 0, 0, 20, NULL, 'active', 'United Kingdom'),
    (1009, 7, 10.00, 5.00, 5.00, NULL, 3.00, 'active', 'United Kingdom'),
    (1010, 8, 10.00, 0, 0, 20, 0.50, 'active', 'United Kingdom'),
    (1011, 9, 10.00, 0, 0, 20, 2.00, 'active', ' ireland '),
    (1012, 10, 10.00, 0, NULL, 20, 2.01, 'active', 'United Kingdom'),
    (1013, 11, 10.00, 4.125, 4.125, 20, 2.83, 'active', 'United Kingdom')
) v (external_id, reference, sub_total_net, shipping_total_net, original_shipping_total_net, shipping_vat_rate, tax_value, lifecycle_status, delivery_country);

INSERT INTO shopwired.order_products (
    order_id, order_external_id, external_id, title, sku, price, price_vat, total, total_vat, original_price, quantity, vat_rate, created_at, updated_at
)
SELECT o.id, v.order_external_id, v.external_id, 'Fixture', 'FIXTURE', v.price, v.price_vat, 0, 0, 0, v.quantity, v.vat_rate, now(), now()
FROM (VALUES
    (1001, 2001, 1.234999, 0.247000, 1, 20.000000),
    (1001, 2002, 1.234999, 0.247000, 1, 20.000000),
    (1002, 2003, 1.005000, 0.000000, 1, 0.000000),
    (1003, 2004, 10.000000, 2.000000, 1, 20.000000),
    (1004, 2005, 10.000000, 2.000000, 1, 20.000000),
    (1005, 2006, 10.000000, 2.000000, 1, 20.000000),
    (1006, 2007, 5.000000, 1.000000, 1, 20.000000),
    (1007, 2008, 10.000000, 2.000000, 1, 20.000000),
    (1008, 2009, 10.000000, 2.000000, 1, 20.000000),
    (1009, 2010, 10.000000, 2.000000, 1, 20.000000),
    (1010, 2011, 10.000000, 0.500000, 1, 5.000000),
    (1011, 2012, 10.000000, 2.000000, 1, 20.000000),
    (1012, 2013, 10.000000, 2.000000, 1, 20.000000),
    (1013, 2014, 10.000000, 2.000000, 1, 20.000000)
) v (order_external_id, external_id, price, price_vat, quantity, vat_rate)
JOIN shopwired.orders o ON o.external_id = v.order_external_id;

INSERT INTO shopwired.order_refunds (order_id, order_external_id, external_id, name, value, created_at, updated_at)
SELECT o.id, o.external_id, 3000 + o.external_id, 'Fixture', 1.00, now(), now()
FROM shopwired.orders o
WHERE o.external_id IN (1003, 1004);

\ir vat-replay.sql

ROLLBACK;
