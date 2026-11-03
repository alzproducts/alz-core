<?php

declare(strict_types=1);

namespace Database\Seeders\ProductSlice;

/**
 * Fully-qualified slice table names, in insert order.
 */
final class SliceTables
{
    public const string CUSTOM_FIELD_DEFINITIONS = 'shopwired.custom_field_definitions';

    public const string CATEGORIES = 'shopwired.categories';

    public const string FILTER_GROUPS = 'shopwired.filter_groups';

    public const string PRODUCTS = 'shopwired.products';

    public const string PRODUCT_VARIATIONS = 'shopwired.product_variations';

    public const string STOCK_ITEMS = 'linnworks.stock_items';

    public const string STOCK_ITEM_EXTENDED_PROPERTIES = 'linnworks.stock_item_extended_properties';

    public const string SUPPLIERS = 'linnworks.suppliers';

    public const string STOCK_ITEM_SUPPLIERS = 'linnworks.stock_item_suppliers';

    public const string PRODUCT_EXTRA_DATA = 'catalog.product_extra_data';

    public const string PRICE_PERIODS = 'operations.price_periods';

    public const string COST_PRICE_CHANGES = 'catalog.cost_price_changes';

    public const string PRODUCT_POPULARITY_SNAPSHOTS = 'catalog.product_popularity_snapshots';

    public const string SKU_POPULARITY_SNAPSHOTS = 'catalog.sku_popularity_snapshots';

    public const array INSERT_ORDER = [
        self::CUSTOM_FIELD_DEFINITIONS,
        self::CATEGORIES,
        self::FILTER_GROUPS,
        self::PRODUCTS,
        self::PRODUCT_VARIATIONS,
        self::STOCK_ITEMS,
        self::STOCK_ITEM_EXTENDED_PROPERTIES,
        self::SUPPLIERS,
        self::STOCK_ITEM_SUPPLIERS,
        self::PRODUCT_EXTRA_DATA,
        self::PRICE_PERIODS,
        self::COST_PRICE_CHANGES,
        self::PRODUCT_POPULARITY_SNAPSHOTS,
        self::SKU_POPULARITY_SNAPSHOTS,
    ];
}
