<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | API Configuration
    |--------------------------------------------------------------------------
    */
    'base_url' => env('MIXPANEL_BASE_URL', 'https://api-eu.mixpanel.com'),
    'export_api_base_url' => env('MIXPANEL_EXPORT_API_BASE_URL', 'https://data-eu.mixpanel.com'),
    'project_id' => env('MIXPANEL_PROJECT_ID'),

    /*
    |--------------------------------------------------------------------------
    | Analytics Salt (for order_id_hashed - must match frontend)
    |--------------------------------------------------------------------------
    */
    'analytics_salt' => env('ANALYTICS_SALT'),

    /*
    |--------------------------------------------------------------------------
    | Lookup Tables (production IDs as defaults, env override for testing)
    |--------------------------------------------------------------------------
    */
    'lookup_tables' => [
        'utm_campaigns' => env('MIXPANEL_LOOKUP_TABLE_UTM_CAMPAIGNS', '321195e7-7672-4d3b-9f05-0265b5133bb6'),
        'order_enrichment' => env('MIXPANEL_LOOKUP_TABLE_ORDER_ENRICHMENT', 'cdd4c821-ae5c-495c-bbdc-2f86bb5bfc91'),
        'product_enrichment' => env('MIXPANEL_LOOKUP_TABLE_PRODUCT_ENRICHMENT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Service Account Credentials
    |--------------------------------------------------------------------------
    */
    'service_account_username' => env('MIXPANEL_SERVICE_ACCOUNT_USERNAME'),
    'service_account_password' => env('MIXPANEL_SERVICE_ACCOUNT_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Order Sync Settings
    |--------------------------------------------------------------------------
    */
    // Allow empty export results (for initial sync when no events exist yet)
    // DANGEROUS: Disables deduplication safety check - only use for bootstrapping
    'allow_empty_export' => env('MIXPANEL_ALLOW_EMPTY_EXPORT', false),

    /*
    |--------------------------------------------------------------------------
    | HTTP Transport Settings
    |--------------------------------------------------------------------------
    */
    'timeout' => env('MIXPANEL_TIMEOUT', 30),
    'retry_times' => env('MIXPANEL_RETRY_TIMES', 3),
    'retry_delay' => env('MIXPANEL_RETRY_DELAY', 100),
];
