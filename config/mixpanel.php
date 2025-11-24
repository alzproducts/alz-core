<?php

declare(strict_types=1);

return [
    'base_url' => env('MIXPANEL_BASE_URL', 'https://api.mixpanel.com'),
    'project_id' => env('MIXPANEL_PROJECT_ID'),
    'service_account_username' => env('MIXPANEL_SERVICE_ACCOUNT_USERNAME'),
    'service_account_password' => env('MIXPANEL_SERVICE_ACCOUNT_PASSWORD'),
    'utm_campaign_lookup_table_id' => env('MIXPANEL_UTM_CAMPAIGN_LOOKUP_TABLE_ID'),
];
