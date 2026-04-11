<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Shopwired API Credentials
    |--------------------------------------------------------------------------
    |
    | HTTP Basic Auth credentials for the Shopwired e-commerce API.
    | Get these from your Shopwired dashboard API settings.
    |
    | Docs: https://shopwired.readme.io/docs/getting-started
    |
    */

    'api_key' => env('SHOPWIRED_API_KEY'),
    'api_secret' => env('SHOPWIRED_API_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | API Logging
    |--------------------------------------------------------------------------
    |
    | Control API request/response logging for debugging purposes.
    |
    | Supported values:
    | - null/empty: No logging (zero overhead, recommended for production)
    | - 'info': Log endpoint, status code, and duration
    | - 'debug': Log full request/response bodies (truncated to 1000 chars)
    |
    | Security: Auth credentials are never logged.
    |
    */

    'log_level' => env('SHOPWIRED_LOG_LEVEL'),

    /*
    |--------------------------------------------------------------------------
    | Request Configuration
    |--------------------------------------------------------------------------
    |
    | timeout: Maximum request timeout in seconds (1-300)
    | retry_times: Number of retry attempts for transient failures (0-10)
    | retry_delay: Initial delay between retries in milliseconds (0-5000)
    |
    */

    'timeout' => 30,
    'retry_times' => 3,
    'retry_delay' => 100,

    /*
    |--------------------------------------------------------------------------
    | Test Order Exclusion
    |--------------------------------------------------------------------------
    |
    | Customer emails to exclude from bulk order retrieval (e.g., for Mixpanel
    | sync). Used to filter out test orders placed by developers in production.
    |
    | Orders from these emails are excluded from getOrdersInDateRange() and
    | lookup table generation, but remain accessible via getByReference().
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Standard Sign Product
    |--------------------------------------------------------------------------
    |
    | ShopWired product ID used as the reference for standard sign cost price
    | matching. When --is-standard-sign is used with generate-variant-skus,
    | variation options are matched against this product's variations to
    | inherit cost prices.
    |
    */

    'standard_sign_product_id' => env('SHOPWIRED_STANDARD_SIGN_PRODUCT_ID'),

    /*
    |--------------------------------------------------------------------------
    | Webhook Verification
    |--------------------------------------------------------------------------
    |
    | HMAC secret used to verify incoming webhook signatures from ShopWired.
    | Webhooks include an X-ShopWired-Signature header containing a SHA-256
    | HMAC of the request body signed with this secret.
    |
    */

    'webhook_secret' => env('SHOPWIRED_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Sale Category
    |--------------------------------------------------------------------------
    |
    | ShopWired category ID for products currently on sale.
    | Products are added to this category when entering sale and
    | removed when the sale ends (manual or automatic).
    |
    */

    'sale_category_id' => (int) env('SHOPWIRED_SALE_CATEGORY_ID', 64939),

    /*
    |--------------------------------------------------------------------------
    | Best Sellers Category Limit
    |--------------------------------------------------------------------------
    |
    | How many products (ordered by final_score DESC from the popularity ranking
    | snapshot) qualify for the Best Sellers category (ID 64943).
    | Products outside the top N are removed from the category on the next daily
    | sync at 04:00 Europe/London.
    |
    */

    'best_sellers_limit' => (int) env('SHOPWIRED_BEST_SELLERS_LIMIT', 48),

    /*
    |--------------------------------------------------------------------------
    | Best Sellers Category ID
    |--------------------------------------------------------------------------
    |
    | ShopWired category ID used by the daily best-sellers sync. Kept in sync
    | with the literal baked into the catalog.products_best_sellers_ranking_state
    | database view — if this value changes, the view migration must be replaced.
    |
    */

    'best_sellers_category_id' => (int) env('SHOPWIRED_BEST_SELLERS_CATEGORY_ID', 64943),

    /*
    |--------------------------------------------------------------------------
    | Webhook Staleness Window
    |--------------------------------------------------------------------------
    |
    | Webhook events older than this many hours are discarded without processing.
    | Protects against replayed or severely delayed events causing stale writes.
    |
    */

    'webhook_staleness_hours' => 24,

    'excluded_customer_emails' => array_values(array_filter(
        [
            env('EMAIL_PRIMARY'),
            env('EMAIL_SECONDARY'),
        ],
        static fn(mixed $email): bool => is_string($email) && $email !== '',
    )),

    /*
    |--------------------------------------------------------------------------
    | Test Product
    |--------------------------------------------------------------------------
    |
    | Known product used for development testing (e.g., price updates,
    | SKU operations). Safe to use for manual API calls during development.
    |
    */

    'test_product' => [
        'product_id' => 5585518,
        'sku' => '1005356',
    ],

];
