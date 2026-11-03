-- COR-252 products seed profile export.
-- One read-only SELECT returning one jsonb value: counts and structural distributions only.
-- It returns no prices, costs, margins, titles, SKUs, GTINs, supplier or customer names.
WITH p AS (SELECT * FROM shopwired.products),
v AS (SELECT * FROM shopwired.product_variations),
si AS (SELECT * FROM linnworks.stock_items),
sis AS (SELECT * FROM linnworks.stock_item_suppliers),
c AS (SELECT * FROM shopwired.categories),
all_skus AS (
    SELECT sku FROM p WHERE sku IS NOT NULL
    UNION
    SELECT sku FROM v WHERE sku IS NOT NULL
),
ancestors(cat_id, ancestor_id) AS (
    -- intentional: mirrors catalog.products_view's ancestry CTE so the row count is comparable
    WITH RECURSIVE a(cat_id, ancestor_id) AS (
        SELECT c.external_id, c.external_id FROM shopwired.categories c
        UNION ALL
        SELECT a.cat_id, pid.val::int
        FROM a
        JOIN shopwired.categories c ON c.external_id = a.ancestor_id
        CROSS JOIN LATERAL jsonb_array_elements_text(c.parent_ids) AS pid(val)
    )
    SELECT * FROM a
),
main_cats AS (
    SELECT external_id FROM c WHERE custom_fields @> '{"is_main_category": true}'
),
latest_sku_snapshot AS (
    SELECT max(snapshot_date) AS d FROM catalog.sku_popularity_snapshots
)
SELECT jsonb_build_object(
    'exported_at', now(),
    'counts', jsonb_build_object(
        'products', (SELECT count(*) FROM p),
        'products_active', (SELECT count(*) FROM p WHERE is_active),
        'products_view', (SELECT count(*) FROM catalog.products_view),
        'variations', (SELECT count(*) FROM v),
        'stock_items', (SELECT count(*) FROM si),
        'stock_item_suppliers', (SELECT count(*) FROM sis),
        'suppliers', (SELECT count(*) FROM linnworks.suppliers),
        'categories', (SELECT count(*) FROM c),
        'categories_active', (SELECT count(*) FROM c WHERE active),
        'categories_main', (SELECT count(*) FROM main_cats),
        'filter_groups', (SELECT count(*) FROM shopwired.filter_groups),
        'custom_field_definitions', (SELECT count(*) FROM shopwired.custom_field_definitions),
        'free_delivery_shipping_costs', (SELECT count(*) FROM catalog.free_delivery_shipping_costs),
        'product_extra_data', (SELECT count(*) FROM catalog.product_extra_data),
        'price_periods', (SELECT count(*) FROM operations.price_periods),
        'price_periods_current', (SELECT count(*) FROM operations.price_periods WHERE effective_to IS NULL),
        'cost_price_changes', (SELECT count(*) FROM catalog.cost_price_changes),
        'sku_aliases', (SELECT count(*) FROM catalog.sku_aliases),
        'product_sale_settings', (SELECT count(*) FROM shopwired.product_sale_settings)
    ),
    'products', jsonb_build_object(
        'variations_per_product', (
            SELECT jsonb_object_agg(n::text, products ORDER BY n)
            FROM (
                SELECT n, count(*) AS products
                FROM (SELECT count(v.id) AS n FROM p LEFT JOIN v ON v.product_id = p.id GROUP BY p.id) x
                GROUP BY n
            ) h
        ),
        'categories_per_product', (
            SELECT jsonb_object_agg(n::text, products ORDER BY n)
            FROM (
                SELECT coalesce(jsonb_array_length(category_ids), 0) AS n, count(*) AS products
                FROM p GROUP BY 1
            ) h
        ),
        'images_per_product', (
            SELECT jsonb_object_agg(n::text, products ORDER BY n)
            FROM (
                SELECT coalesce(jsonb_array_length(images), 0) AS n, count(*) AS products
                FROM p GROUP BY 1
            ) h
        ),
        'duplicate_title_groups', (SELECT count(*) FROM (SELECT title FROM p GROUP BY title HAVING count(*) > 1) d),
        'products_in_duplicate_titles', (SELECT coalesce(sum(n), 0) FROM (SELECT count(*) AS n FROM p GROUP BY title HAVING count(*) > 1) d),
        'max_duplicate_title_group', (SELECT coalesce(max(n), 0) FROM (SELECT count(*) AS n FROM p GROUP BY title) d),
        'sku_null', (SELECT count(*) FROM p WHERE sku IS NULL),
        'sku_untrimmed', (SELECT count(*) FROM p WHERE sku <> btrim(sku)),
        'sku_with_stock_item', (SELECT count(*) FROM p WHERE EXISTS (SELECT 1 FROM si WHERE si.item_number = p.sku)),
        'gtin_null_or_empty', (SELECT count(*) FROM p WHERE coalesce(btrim(gtin), '') = ''),
        'gtin_untrimmed', (SELECT count(*) FROM p WHERE gtin <> btrim(gtin)),
        'price_null', (SELECT count(*) FROM p WHERE price IS NULL),
        'price_zero', (SELECT count(*) FROM p WHERE price = 0),
        'price_over_2dp', (SELECT count(*) FROM p WHERE price <> round(price, 2)),
        'sale_price_positive', (SELECT count(*) FROM p WHERE sale_price > 0),
        'sale_price_zero', (SELECT count(*) FROM p WHERE sale_price = 0),
        'sale_price_over_2dp', (SELECT count(*) FROM p WHERE sale_price <> round(sale_price, 2)),
        'sale_price_not_below_price', (SELECT count(*) FROM p WHERE sale_price > 0 AND sale_price >= price),
        'compare_price_positive', (SELECT count(*) FROM p WHERE compare_price > 0),
        'cost_price_null', (SELECT count(*) FROM p WHERE cost_price IS NULL),
        'cost_price_zero', (SELECT count(*) FROM p WHERE cost_price = 0),
        'vat_exclusive', (SELECT count(*) FROM p WHERE vat_exclusive),
        'vat_relief', (SELECT count(*) FROM p WHERE vat_relief),
        'view_is_on_sale', (SELECT count(*) FROM catalog.products_view WHERE is_on_sale),
        'view_has_any_sale', (SELECT count(*) FROM catalog.products_view WHERE has_any_sale),
        'in_any_main_category_tree', (
            SELECT count(DISTINCT p.id)
            FROM p
            CROSS JOIN LATERAL jsonb_array_elements_text(p.category_ids) AS cid(val)
            JOIN ancestors a ON a.cat_id = cid.val::int
            JOIN main_cats mc ON mc.external_id = a.ancestor_id
        ),
        'custom_field_keys', (
            SELECT jsonb_object_agg(k, types)
            FROM (
                SELECT k, jsonb_object_agg(t, n) AS types
                FROM (
                    SELECT e.key AS k, jsonb_typeof(e.value) AS t, count(*) AS n
                    FROM p CROSS JOIN LATERAL jsonb_each(CASE WHEN jsonb_typeof(p.custom_fields) = 'object' THEN p.custom_fields ELSE '{}' END) e
                    GROUP BY 1, 2
                ) kt
                GROUP BY k
            ) agg
        ),
        'custom_fields_not_object', (
            SELECT jsonb_object_agg(coalesce(t, '<null>'), n)
            FROM (SELECT jsonb_typeof(custom_fields) AS t, count(*) AS n FROM p WHERE jsonb_typeof(custom_fields) IS DISTINCT FROM 'object' GROUP BY 1) h
        ),
        'free_delivery_values', (
            SELECT jsonb_object_agg(coalesce(val, '<null>'), n)
            FROM (SELECT custom_fields ->> 'free_delivery' AS val, count(*) AS n FROM p GROUP BY 1) h
        ),
        'filter_slots', (
            SELECT jsonb_object_agg(slot, jsonb_build_object(
                'products', products, 'max_values', max_values, 'avg_values', avg_values, 'distinct_values', distinct_values
            ))
            FROM (
                SELECT f.key AS slot,
                       count(*) AS products,
                       max(CASE WHEN jsonb_typeof(f.value) = 'array' THEN jsonb_array_length(f.value) ELSE 1 END) AS max_values,
                       round(avg(CASE WHEN jsonb_typeof(f.value) = 'array' THEN jsonb_array_length(f.value) ELSE 1 END), 2) AS avg_values,
                       (SELECT count(DISTINCT x.val)
                        FROM p p2
                        CROSS JOIN LATERAL jsonb_array_elements_text(
                            CASE WHEN jsonb_typeof(p2.filters -> f.key) = 'array' THEN p2.filters -> f.key ELSE '[]' END
                        ) AS x(val)) AS distinct_values
                FROM p CROSS JOIN LATERAL jsonb_each(CASE WHEN jsonb_typeof(p.filters) = 'object' THEN p.filters ELSE '{}' END) f
                GROUP BY f.key
            ) s
        )
    ),
    'variations', jsonb_build_object(
        'options_per_variation', (
            SELECT jsonb_object_agg(n::text, variations ORDER BY n)
            FROM (SELECT coalesce(jsonb_array_length(options), 0) AS n, count(*) AS variations FROM v GROUP BY 1) h
        ),
        'distinct_options_per_product', (
            SELECT jsonb_object_agg(n::text, products ORDER BY n)
            FROM (
                SELECT n, count(*) AS products
                FROM (
                    SELECT v.product_id, count(DISTINCT o ->> 'option_id') AS n
                    FROM v CROSS JOIN LATERAL jsonb_array_elements(coalesce(v.options, '[]')) o
                    GROUP BY v.product_id
                ) x
                GROUP BY n
            ) h
        ),
        'sku_null', (SELECT count(*) FROM v WHERE sku IS NULL),
        'sku_untrimmed', (SELECT count(*) FROM v WHERE sku <> btrim(sku)),
        'with_stock_item', (SELECT count(*) FROM v WHERE EXISTS (SELECT 1 FROM si WHERE si.item_number = v.sku)),
        'without_stock_item', (SELECT count(*) FROM v WHERE NOT EXISTS (SELECT 1 FROM si WHERE si.item_number = v.sku)),
        'gtin_null_or_empty', (SELECT count(*) FROM v WHERE coalesce(btrim(gtin), '') = ''),
        'gtin_untrimmed', (SELECT count(*) FROM v WHERE gtin <> btrim(gtin)),
        'price_null', (SELECT count(*) FROM v WHERE price IS NULL),
        'price_zero', (SELECT count(*) FROM v WHERE price = 0),
        'price_over_2dp', (SELECT count(*) FROM v WHERE price <> round(price, 2)),
        'sale_price_null', (SELECT count(*) FROM v WHERE sale_price IS NULL),
        'sale_price_zero', (SELECT count(*) FROM v WHERE sale_price = 0),
        'sale_price_positive', (SELECT count(*) FROM v WHERE sale_price > 0),
        'cost_price_null', (SELECT count(*) FROM v WHERE cost_price IS NULL),
        'cost_price_zero', (SELECT count(*) FROM v WHERE cost_price = 0),
        'view_is_on_sale', (SELECT count(*) FROM catalog.product_variations_view WHERE is_on_sale)
    ),
    'stock_items', jsonb_build_object(
        'item_number_null_or_empty', (SELECT count(*) FROM si WHERE coalesce(btrim(item_number), '') = ''),
        'item_number_untrimmed', (SELECT count(*) FROM si WHERE item_number <> btrim(item_number)),
        'item_number_duplicated', (SELECT count(*) FROM (SELECT item_number FROM si GROUP BY item_number HAVING count(*) > 1) d),
        'matches_product_sku', (SELECT count(*) FROM si WHERE EXISTS (SELECT 1 FROM p WHERE p.sku = si.item_number)),
        'matches_variation_sku', (SELECT count(*) FROM si WHERE EXISTS (SELECT 1 FROM v WHERE v.sku = si.item_number)),
        'matches_no_sku', (SELECT count(*) FROM si WHERE NOT EXISTS (SELECT 1 FROM all_skus s WHERE s.sku = si.item_number)),
        'suppliers_per_stock_item', (
            SELECT jsonb_object_agg(n::text, stock_items ORDER BY n)
            FROM (
                SELECT n, count(*) AS stock_items
                FROM (SELECT count(sis.id) AS n FROM si LEFT JOIN sis ON sis.stock_item_id = si.stock_item_id GROUP BY si.id) x
                GROUP BY n
            ) h
        ),
        'purchase_price_null', (SELECT count(*) FROM si WHERE purchase_price IS NULL),
        'purchase_price_zero', (SELECT count(*) FROM si WHERE purchase_price = 0),
        'is_composite', (SELECT count(*) FROM si WHERE is_composite),
        'is_archived', (SELECT count(*) FROM si WHERE is_archived),
        'is_logically_deleted', (SELECT count(*) FROM si WHERE is_logically_deleted),
        'jit', (SELECT count(*) FROM si WHERE jit)
    ),
    'stock_item_suppliers', jsonb_build_object(
        'is_default', (SELECT count(*) FROM sis WHERE is_default),
        'default_with_purchase_price', (SELECT count(*) FROM sis WHERE is_default AND purchase_price IS NOT NULL),
        'purchase_price_null', (SELECT count(*) FROM sis WHERE purchase_price IS NULL),
        'purchase_price_zero', (SELECT count(*) FROM sis WHERE purchase_price = 0),
        'purchase_price_over_2dp', (SELECT count(*) FROM sis WHERE purchase_price <> round(purchase_price, 2)),
        'stock_items_with_multiple_defaults', (
            SELECT count(*) FROM (SELECT stock_item_id FROM sis WHERE is_default GROUP BY 1 HAVING count(*) > 1) d
        ),
        'links_without_stock_item', (
            SELECT count(*) FROM sis WHERE NOT EXISTS (SELECT 1 FROM si WHERE si.stock_item_id = sis.stock_item_id)
        ),
        'distinct_supplier_ids', (SELECT count(DISTINCT supplier_id) FROM sis),
        'supplier_ids_not_in_suppliers', (
            SELECT count(DISTINCT supplier_id) FROM sis
            WHERE NOT EXISTS (SELECT 1 FROM linnworks.suppliers s WHERE s.pk_supplier_id = sis.supplier_id)
        ),
        'links_per_supplier', (
            SELECT jsonb_object_agg(bucket, suppliers)
            FROM (
                SELECT CASE WHEN n <= 10 THEN n::text WHEN n <= 25 THEN '11-25' WHEN n <= 50 THEN '26-50'
                            WHEN n <= 100 THEN '51-100' WHEN n <= 250 THEN '101-250' ELSE '251+' END AS bucket,
                       count(*) AS suppliers
                FROM (SELECT count(*) AS n FROM sis GROUP BY supplier_id) x
                GROUP BY 1
            ) h
        ),
        'supplier_currency', (
            SELECT jsonb_object_agg(coalesce(supplier_currency, '<null>'), n)
            FROM (SELECT supplier_currency, count(*) AS n FROM sis GROUP BY 1) h
        )
    ),
    'categories', jsonb_build_object(
        'parent_ids_length', (
            SELECT jsonb_object_agg(n::text, categories ORDER BY n)
            FROM (SELECT coalesce(jsonb_array_length(parent_ids), 0) AS n, count(*) AS categories FROM c GROUP BY 1) h
        ),
        'depth', (
            SELECT jsonb_object_agg(depth::text, categories ORDER BY depth)
            FROM (
                SELECT depth, count(*) AS categories
                FROM (SELECT cat_id, count(*) - 1 AS depth FROM ancestors GROUP BY cat_id) x
                GROUP BY depth
            ) h
        ),
        'children_per_parent', (
            SELECT jsonb_object_agg(n::text, parents ORDER BY n)
            FROM (
                SELECT n, count(*) AS parents
                FROM (
                    SELECT pid.val, count(*) AS n
                    FROM c CROSS JOIN LATERAL jsonb_array_elements_text(c.parent_ids) AS pid(val)
                    GROUP BY pid.val
                ) x
                GROUP BY n
            ) h
        ),
        'ancestor_rows', (SELECT count(*) FROM ancestors),
        'cat_main_map_rows', (
            SELECT count(*) FROM (SELECT DISTINCT a.cat_id, a.ancestor_id FROM ancestors a JOIN main_cats m ON m.external_id = a.ancestor_id) d
        ),
        'products_per_category', (
            SELECT jsonb_object_agg(bucket, categories)
            FROM (
                SELECT CASE WHEN n = 0 THEN '0' WHEN n <= 5 THEN '1-5' WHEN n <= 20 THEN '6-20' WHEN n <= 50 THEN '21-50'
                            WHEN n <= 100 THEN '51-100' WHEN n <= 250 THEN '101-250' ELSE '251+' END AS bucket,
                       count(*) AS categories
                FROM (
                    SELECT c.external_id, count(p.id) AS n
                    FROM c LEFT JOIN p ON p.category_ids @> to_jsonb(ARRAY[c.external_id])
                    GROUP BY c.external_id
                ) x
                GROUP BY 1
            ) h
        ),
        'custom_field_keys', (
            SELECT jsonb_object_agg(k, n)
            FROM (
                SELECT e.key AS k, count(*) AS n
                FROM c CROSS JOIN LATERAL jsonb_each(CASE WHEN jsonb_typeof(c.custom_fields) = 'object' THEN c.custom_fields ELSE '{}' END) e
                GROUP BY 1
            ) h
        ),
        'custom_fields_not_object', (SELECT count(*) FROM c WHERE jsonb_typeof(custom_fields) <> 'object')
    ),
    'custom_field_definitions', (
        SELECT jsonb_object_agg(item_type || '/' || type, n)
        FROM (SELECT item_type, type, count(*) AS n FROM shopwired.custom_field_definitions GROUP BY 1, 2) h
    ),
    'filter_group_option_nos', (SELECT jsonb_agg(option_no ORDER BY option_no) FROM shopwired.filter_groups),
    'free_delivery_types', (SELECT jsonb_agg(delivery_type ORDER BY delivery_type) FROM catalog.free_delivery_shipping_costs),
    'product_extra_data', jsonb_build_object(
        'rrp_null', (SELECT count(*) FROM catalog.product_extra_data WHERE rrp IS NULL),
        'sku_matches_catalog', (SELECT count(*) FROM catalog.product_extra_data e WHERE EXISTS (SELECT 1 FROM all_skus s WHERE s.sku = e.sku))
    ),
    'price_periods', jsonb_build_object(
        'distinct_skus', (SELECT count(DISTINCT sku) FROM operations.price_periods),
        'current_sku_matches_catalog', (
            SELECT count(*) FROM operations.price_periods pp
            WHERE pp.effective_to IS NULL AND EXISTS (SELECT 1 FROM all_skus s WHERE s.sku = pp.sku)
        ),
        'price_has_tax', (SELECT count(*) FROM operations.price_periods WHERE price_has_tax),
        'periods_per_sku', (
            SELECT jsonb_object_agg(bucket, skus)
            FROM (
                SELECT CASE WHEN n <= 5 THEN n::text WHEN n <= 10 THEN '6-10' WHEN n <= 25 THEN '11-25' ELSE '26+' END AS bucket,
                       count(*) AS skus
                FROM (SELECT count(*) AS n FROM operations.price_periods GROUP BY sku) x
                GROUP BY 1
            ) h
        )
    ),
    'cost_price_changes', jsonb_build_object(
        'distinct_skus', (SELECT count(DISTINCT sku) FROM catalog.cost_price_changes),
        'distinct_supplier_ids', (SELECT count(DISTINCT supplier_id) FROM catalog.cost_price_changes),
        'changes_per_sku', (
            SELECT jsonb_object_agg(bucket, skus)
            FROM (
                SELECT CASE WHEN n <= 5 THEN n::text WHEN n <= 10 THEN '6-10' WHEN n <= 25 THEN '11-25' ELSE '26+' END AS bucket,
                       count(*) AS skus
                FROM (SELECT count(*) AS n FROM catalog.cost_price_changes GROUP BY sku) x
                GROUP BY 1
            ) h
        )
    ),
    'popularity', jsonb_build_object(
        'sku_snapshot_dates', (SELECT count(DISTINCT snapshot_date) FROM catalog.sku_popularity_snapshots),
        'sku_snapshot_rows', (SELECT count(*) FROM catalog.sku_popularity_snapshots),
        'sku_latest_rows', (
            SELECT count(*) FROM catalog.sku_popularity_snapshots s, latest_sku_snapshot l WHERE s.snapshot_date = l.d
        ),
        'sku_latest_matches_catalog', (
            SELECT count(*) FROM catalog.sku_popularity_snapshots s, latest_sku_snapshot l
            WHERE s.snapshot_date = l.d AND EXISTS (SELECT 1 FROM all_skus a WHERE a.sku = s.live_sku)
        ),
        'sku_rows_per_date', (
            SELECT jsonb_build_object('min', min(n), 'max', max(n), 'avg', round(avg(n), 1))
            FROM (SELECT count(*) AS n FROM catalog.sku_popularity_snapshots GROUP BY snapshot_date) x
        ),
        'product_latest_rows', (SELECT count(*) FROM catalog.product_popularity_ranking_latest),
        'credit_latest_rows', (SELECT count(*) FROM catalog.credit_product_popularity_ranking_latest),
        'credit_tiers', (
            SELECT jsonb_object_agg(coalesce(credit_tier, '<null>'), n)
            FROM (SELECT credit_tier, count(*) AS n FROM catalog.credit_product_popularity_ranking_latest GROUP BY 1) h
        )
    )
) AS profile;
