<?php

declare(strict_types=1);

return [
    'developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN'),
    'client_id' => env('GOOGLE_ADS_CLIENT_ID'),
    'client_secret' => env('GOOGLE_ADS_CLIENT_SECRET'),
    'refresh_token' => env('GOOGLE_ADS_REFRESH_TOKEN'),
    'customer_id' => env('GOOGLE_ADS_CUSTOMER_ID'),
    'login_customer_id' => env('GOOGLE_ADS_LOGIN_CUSTOMER_ID'),
    'lead_conversion_action_id' => env('GOOGLE_ADS_LEAD_CONVERSION_ID'),
    'quote_conversion_action_id' => env('GOOGLE_ADS_QUOTE_CONVERSION_ID'),
];
