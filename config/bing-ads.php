<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | OAuth 2.0 Credentials
    |--------------------------------------------------------------------------
    |
    | Azure AD application credentials for OAuth 2.0 authentication.
    | Create at: https://portal.azure.com/#blade/Microsoft_AAD_RegisteredApps/ApplicationsListBlade
    |
    */

    'client_id' => env('BING_ADS_CLIENT_ID'),
    'client_secret' => env('BING_ADS_CLIENT_SECRET'),
    'refresh_token' => env('BING_ADS_REFRESH_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Microsoft Advertising Credentials
    |--------------------------------------------------------------------------
    |
    | Developer token from: https://developers.bingads.microsoft.com/Account
    | Account/Customer IDs from Microsoft Advertising UI.
    |
    */

    'developer_token' => env('BING_ADS_DEVELOPER_TOKEN'),
    'account_id' => env('BING_ADS_ACCOUNT_ID'),
    'customer_id' => env('BING_ADS_CUSTOMER_ID'),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | API environment: 'Production' or 'Sandbox'.
    |
    */

    'environment' => env('BING_ADS_ENVIRONMENT', 'Production'),

    /*
    |--------------------------------------------------------------------------
    | Async Report Polling Configuration
    |--------------------------------------------------------------------------
    |
    | Bing Ads reports are generated asynchronously. These settings control
    | how often and how long we poll for report completion.
    |
    | Default: 10s interval × 30 attempts = 300s max wait (5 minutes)
    |
    */

    'report_poll_interval_seconds' => (int) env('BING_ADS_REPORT_POLL_INTERVAL', 10),
    'report_poll_max_attempts' => (int) env('BING_ADS_REPORT_POLL_MAX_ATTEMPTS', 30),
];
