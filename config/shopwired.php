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

    'excluded_customer_emails' => array_values(array_filter(
        [
            env('EMAIL_PRIMARY'),
            env('EMAIL_SECONDARY'),
        ],
        static fn(mixed $email): bool => is_string($email) && $email !== '',
    )),

];
