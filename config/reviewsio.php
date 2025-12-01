<?php

declare(strict_types=1);

return [
    /*
        |--------------------------------------------------------------------------
        | Reviews.io API Credentials
        |--------------------------------------------------------------------------
        |
        | These credentials are required for Reviews.io integration.
        | Set them in your .env file.
        |
        */
    'api_key'     => env('REVIEWSIO_API_KEY'),
    'store_id'    => env('REVIEWSIO_STORE'),

    /*
        |--------------------------------------------------------------------------
        | Technical Configuration
        |--------------------------------------------------------------------------
        |
        | These are technical settings that typically don't change per environment.
        | Hardcoded defaults unless you need environment-specific overrides.
        |
        */
    'timeout'     => 30,           // Hardcoded - no env var needed
    'retry_times' => 3,        // Hardcoded - no env var needed
    'retry_delay' => 100,      // Hardcoded - no env var needed

    /*
        |--------------------------------------------------------------------------
        | Feature Configuration
        |--------------------------------------------------------------------------
        |
        | Business logic configuration that might differ per environment.
        |
        */
    'enabled'     => env('REVIEWSIO_ENABLED', true),
];
