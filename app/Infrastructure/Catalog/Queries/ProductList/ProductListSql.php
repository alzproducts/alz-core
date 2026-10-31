<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Queries\ProductList;

/**
 * The statement that pages GET /api/products and fingerprints each product's list inputs, and the
 * one-row read of the inputs every page product shares.
 *
 * `list_inputs` is the only source the list assembler reads, and its row text is what the
 * fingerprint hashes, so a column added here is covered by the fragment cache with no further step.
 * Every json_agg pins an ORDER BY on a unique key: the hash covers the aggregate's text.
 */
final class ProductListSql
{
    /** @var list<string> */
    public const array PAGE_COLUMNS = [
        'id', 'external_id', 'sku', 'gtin', 'title', 'slug', 'url', 'price', 'cost_price', 'sale_price',
        'effective_price', 'is_on_sale', 'has_any_sale', 'profit_margin', 'is_active', 'vat_exclusive', 'vat_relief',
        'meta_title', 'meta_description', 'category_ids', 'main_category_ids', 'images', 'custom_fields', 'sort_order',
        'popularity_rank', 'popularity_max', 'shopwired_created_at', 'shopwired_updated_at', 'available_stock',
        'physical_stock', 'price_last_updated_at', 'cost_price_last_updated_at',
    ];

    /** @var array<string, string> list_inputs column => SQL expression */
    public const array LIST_INPUT_COLUMNS = [
        'id' => 'p.id', 'external_id' => 'p.external_id', 'sku' => 'p.sku', 'gtin' => 'p.gtin', 'title' => 'p.title',
        'slug' => 'p.slug', 'url' => 'p.url', 'price' => 'p.price', 'cost_price' => 'p.cost_price',
        'sale_price' => 'p.sale_price', 'rrp' => 'e.rrp', 'effective_price' => 'p.effective_price',
        'is_on_sale' => 'p.is_on_sale', 'has_any_sale' => 'p.has_any_sale', 'profit_margin' => 'p.profit_margin',
        'is_active' => 'p.is_active', 'vat_exclusive' => 'p.vat_exclusive', 'vat_relief' => 'p.vat_relief',
        'meta_title' => 'p.meta_title', 'meta_description' => 'p.meta_description', 'category_ids' => 'p.category_ids',
        'main_category_ids' => 'p.main_category_ids', 'images' => 'p.images', 'custom_fields' => 'p.custom_fields',
        'sort_order' => 'p.sort_order', 'popularity_rank' => 'p.popularity_rank', 'popularity_max' => 'p.popularity_max',
        'shopwired_created_at' => 'p.shopwired_created_at', 'shopwired_updated_at' => 'p.shopwired_updated_at',
        'available_stock' => 'p.available_stock', 'physical_stock' => 'p.physical_stock',
        'price_last_updated_at' => 'p.price_last_updated_at', 'cost_price_last_updated_at' => 'p.cost_price_last_updated_at',
        'stock_item' => 'sj.stock_item', 'variations' => "coalesce(vj.variations, '[]'::json)",
    ];

    /** @var array<string, string> variation JSON key => SQL expression */
    public const array VARIATION_FIELDS = [
        'external_id' => 'v.external_id', 'sku' => 'v.sku', 'gtin' => 'v.gtin', 'price' => 'v.price',
        'cost_price' => 'v.cost_price', 'sale_price' => 'v.sale_price', 'rrp' => 'e.rrp',
        'effective_price' => 'v.effective_price', 'is_on_sale' => 'v.is_on_sale', 'profit_margin' => 'v.profit_margin',
        'available_stock' => 'v.available_stock', 'physical_stock' => 'v.physical_stock', 'weight' => 'v.weight',
        'mpn' => 'v.mpn', 'image_index' => 'v.image_index', 'options' => 'v.options', 'created_at' => 'v.created_at',
        'updated_at' => 'v.updated_at', 'popularity_rank' => 'v.popularity_rank', 'popularity_max' => 'v.popularity_max',
        'stock_value' => 'v.stock_value', 'stock_item' => 'sj.stock_item',
    ];

    /** @var list<string> */
    public const array VARIATION_VIEW_COLUMNS = [
        'external_id', 'sku', 'gtin', 'price', 'cost_price', 'sale_price', 'effective_price', 'is_on_sale',
        'profit_margin', 'available_stock', 'physical_stock', 'weight', 'mpn', 'image_index', 'options', 'created_at',
        'updated_at', 'popularity_rank', 'popularity_max', 'stock_value',
    ];

    /** @var list<string> linnworks.stock_items columns */
    public const array STOCK_ITEM_FIELDS = [
        'barcode', 'minimum_level', 'weight', 'weight_unit', 'height', 'width', 'depth', 'is_composite',
        'category_name', 'quantity', 'available', 'in_order', 'due', 'jit',
    ];

    /** @var list<string> linnworks.stock_item_suppliers columns */
    public const array SUPPLIER_FIELDS = [
        'supplier_name', 'purchase_price', 'is_default', 'code', 'supplier_barcode', 'lead_time',
        'supplier_min_order_qty', 'supplier_pack_size', 'min_price', 'max_price', 'average_price', 'average_lead_time',
    ];

    /** @var list<string> shopwired.custom_field_definitions columns */
    public const array CUSTOM_FIELD_DEFINITION_FIELDS = [
        'id', 'external_id', 'name', 'type', 'label', 'item_type', 'sort_order', 'allowed_values',
    ];

    public const string FRAGMENT_JOIN = 'LEFT JOIN catalog.product_list_fragments f'
        . ' ON f.product_id = h.product_id AND f.shape = ? AND f.fingerprint = h.fingerprint';

    public const string NO_FRAGMENT_JOIN = 'LEFT JOIN catalog.product_list_fragments f ON false';

    private const string SHARED_INPUTS = <<<'SQL'
        SELECT (SELECT coalesce(json_agg(d ORDER BY d.external_id), '[]'::json)
                FROM (SELECT {definition_columns} FROM shopwired.custom_field_definitions c) d) AS custom_field_definitions
        SQL;

    // intentional: the shared inputs stay out of the page rows; each row would carry a copy and spill the page-order sort to disk.
    private const string TEMPLATE = <<<'SQL'
        WITH page AS MATERIALIZED (
        {page}
        ),
        page_variations AS MATERIALIZED (
          SELECT pv.product_id, {variation_columns}
          FROM catalog.product_variations_view pv WHERE pv.product_id IN (SELECT id FROM page)
        ),
        page_skus AS MATERIALIZED (
          SELECT sku FROM page WHERE sku IS NOT NULL UNION SELECT sku FROM page_variations WHERE sku IS NOT NULL
        ),
        stock_item_json AS MATERIALIZED (
          SELECT DISTINCT ON (si.item_number) si.item_number, {stock_item_object} AS stock_item
          FROM linnworks.stock_items si
          LEFT JOIN (
            SELECT DISTINCT ON (ss.stock_item_id) ss.stock_item_id, {supplier_object} AS supplier
            FROM linnworks.stock_item_suppliers ss WHERE ss.is_default
            ORDER BY ss.stock_item_id, ss.id
          ) sp ON sp.stock_item_id = si.stock_item_id
          WHERE si.item_number IN (SELECT sku FROM page_skus)
          ORDER BY si.item_number, si.id
        ),
        page_extra AS MATERIALIZED (
          SELECT e.sku, e.rrp FROM catalog.product_extra_data e WHERE e.sku IN (SELECT sku FROM page_skus)
        ),
        variations_json AS MATERIALIZED (
          SELECT v.product_id, json_agg({variation_object} ORDER BY v.external_id) AS variations
          FROM page_variations v
          LEFT JOIN page_extra e ON e.sku = v.sku
          LEFT JOIN stock_item_json sj ON sj.item_number = v.sku
          GROUP BY v.product_id
        ),
        list_inputs AS (
          SELECT {list_input_columns}
          FROM page p
          LEFT JOIN page_extra e ON e.sku = p.sku
          LEFT JOIN stock_item_json sj ON sj.item_number = p.sku
          LEFT JOIN variations_json vj ON vj.product_id = p.id
        ),
        shared_inputs AS (
        {shared_inputs}
        ),
        shared_hash AS MATERIALIZED (
          SELECT md5(row_to_json(s)::text) AS h FROM shared_inputs s
        ),
        hashed AS MATERIALIZED (
          SELECT x.product_id, x.page_pos, x.inputs, md5(x.inputs::text || ? || sh.h) AS fingerprint
          FROM (SELECT li.id AS product_id, p.page_pos, row_to_json(li) AS inputs
                FROM list_inputs li JOIN page p ON p.id = li.id) x
          CROSS JOIN shared_hash sh
        )
        SELECT h.product_id, h.fingerprint, f.body AS cached_body,
               CASE WHEN f.body IS NULL THEN h.inputs::text END AS inputs
        FROM hashed h
        {fragment_join}
        ORDER BY h.page_pos
        SQL;

    private const string SHARED_INPUTS_STATEMENT = 'SELECT row_to_json(s)::text AS shared_inputs FROM ({shared_inputs}) s';

    /**
     * Bindings: the page SQL's, then the salt, then the shape when $fragmentJoin is {@see self::FRAGMENT_JOIN}.
     *
     * @param string $pageSql selects {@see self::PAGE_COLUMNS} plus `page_pos`
     */
    public static function statement(string $pageSql, string $fragmentJoin): string
    {
        return \strtr(self::TEMPLATE, [
            '{page}' => $pageSql,
            '{variation_columns}' => self::prefixed('pv', self::VARIATION_VIEW_COLUMNS),
            '{stock_item_object}' => self::object(self::keyedQualify('si', self::STOCK_ITEM_FIELDS) + ['default_supplier' => 'sp.supplier']),
            '{supplier_object}' => self::object(self::keyedQualify('ss', self::SUPPLIER_FIELDS)),
            '{variation_object}' => self::object(self::VARIATION_FIELDS),
            '{list_input_columns}' => self::aliased(self::LIST_INPUT_COLUMNS),
            '{shared_inputs}' => self::sharedInputs(),
            '{fragment_join}' => $fragmentJoin,
        ]);
    }

    /**
     * The one-row `shared_inputs` text whose md5 {@see self::statement()} folds into every fingerprint;
     * it matches the fingerprints only when run in the same snapshot.
     */
    public static function sharedInputsStatement(): string
    {
        return \strtr(self::SHARED_INPUTS_STATEMENT, ['{shared_inputs}' => self::sharedInputs()]);
    }

    private static function sharedInputs(): string
    {
        return \strtr(self::SHARED_INPUTS, ['{definition_columns}' => self::prefixed('c', self::CUSTOM_FIELD_DEFINITION_FIELDS)]);
    }

    /**
     * @param array<string, string> $fields
     */
    private static function object(array $fields): string
    {
        $pairs = [];

        foreach ($fields as $key => $expression) {
            $pairs[] = "'{$key}', {$expression}";
        }

        return 'json_build_object(' . \implode(', ', $pairs) . ')';
    }

    /**
     * @param array<string, string> $columns
     */
    private static function aliased(array $columns): string
    {
        $parts = [];

        foreach ($columns as $alias => $expression) {
            $parts[] = "{$expression} AS {$alias}";
        }

        return \implode(', ', $parts);
    }

    /**
     * @param list<string> $columns
     */
    private static function prefixed(string $alias, array $columns): string
    {
        return \implode(', ', self::qualify($alias, $columns));
    }

    /**
     * @param list<string> $columns
     *
     * @return list<string>
     */
    private static function qualify(string $alias, array $columns): array
    {
        return \array_map(static fn(string $column): string => "{$alias}.{$column}", $columns);
    }

    /**
     * @param list<string> $columns
     *
     * @return array<string, string>
     */
    private static function keyedQualify(string $alias, array $columns): array
    {
        $keyed = [];

        foreach ($columns as $column) {
            $keyed[$column] = "{$alias}.{$column}";
        }

        return $keyed;
    }
}
