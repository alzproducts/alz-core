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

    'excluded_customer_emails' => array_values(array_filter(
        [
            env('EMAIL_TOM_MAIN'),
            env('EMAIL_TOM_SECONDARY'),
        ],
        static fn(mixed $email): bool => is_string($email) && $email !== '',
    )),

];
