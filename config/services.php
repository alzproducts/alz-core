<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'supabase' => [
        'jwt_secret' => env('SUPABASE_JWT_SECRET'),

        // Local Development Auth Bypass Configuration
        // Allows testing protected endpoints without a real Supabase JWT
        // Only works when: APP_ENV=local AND request from 127.0.0.1/::1
        'local_bypass_secret' => env('SUPABASE_LOCAL_BYPASS_SECRET'),
        'local_test_email' => env('SUPABASE_LOCAL_TEST_EMAIL'),
        'local_test_user_id' => env('SUPABASE_LOCAL_TEST_USER_ID', '00000000-0000-0000-0000-000000000001'),
        'local_test_approved' => env('SUPABASE_LOCAL_TEST_APPROVED', true),
        'local_test_role' => env('SUPABASE_LOCAL_TEST_ROLE', 'admin'),
        'local_test_departments' => env('SUPABASE_LOCAL_TEST_DEPARTMENTS'),
    ],

    'ad_spend_sync' => [
        'enabled' => env('AD_SPEND_SYNC_ENABLED', true),
    ],

];
