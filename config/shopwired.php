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

];
