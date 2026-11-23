<?php

declare(strict_types=1);

namespace Database\Seeders\OrderSlice;

/**
 * Fully-qualified orders-slice table names, in insert order.
 */
final class OrderSliceTables
{
    public const string ORDERS = 'shopwired.orders';

    public const string ORDER_PRODUCTS = 'shopwired.order_products';

    public const string ORDER_REFUNDS = 'shopwired.order_refunds';

    public const string ORDER_PRODUCT_EXTRA_DATA = 'shopwired.order_product_extra_data';

    public const string ORDER_DISCOUNTS = 'shopwired.order_discounts';

    public const string ORDER_ADMIN_COMMENTS = 'shopwired.order_admin_comments';

    /**
     * Discounts and admin comments are never seeded, but reference orders, so they belong to the slice:
     * `--fresh` truncates them and their rows block a seed without it.
     */
    public const array INSERT_ORDER = [
        self::ORDERS,
        self::ORDER_PRODUCTS,
        self::ORDER_REFUNDS,
        self::ORDER_PRODUCT_EXTRA_DATA,
        self::ORDER_DISCOUNTS,
        self::ORDER_ADMIN_COMMENTS,
    ];
}
