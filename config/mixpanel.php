<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | API Configuration
    |--------------------------------------------------------------------------
    */
    'base_url' => env('MIXPANEL_BASE_URL', 'https://api-eu.mixpanel.com'),
    'project_id' => env('MIXPANEL_PROJECT_ID'),
    'utm_campaign_lookup_table_id' => env('MIXPANEL_UTM_CAMPAIGN_LOOKUP_TABLE_ID'),

    /*
    |--------------------------------------------------------------------------
    | Service Account Credentials
    |--------------------------------------------------------------------------
    */
    'service_account_username' => env('MIXPANEL_SERVICE_ACCOUNT_USERNAME'),
    'service_account_password' => env('MIXPANEL_SERVICE_ACCOUNT_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Transport Settings
    |--------------------------------------------------------------------------
    */
    'timeout' => env('MIXPANEL_TIMEOUT', 30),
    'retry_times' => env('MIXPANEL_RETRY_TIMES', 3),
    'retry_delay' => env('MIXPANEL_RETRY_DELAY', 100),
];
