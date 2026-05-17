<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Mailboxes
    |--------------------------------------------------------------------------
    |
    | HelpScout mailbox IDs used throughout the system.
    |
    */
    'mailboxes' => [
        'support' => (int) env('HELPSCOUT_MAILBOX_SUPPORT'),
        'purchase_orders' => (int) env('HELPSCOUT_MAILBOX_PURCHASE_ORDERS'),
        'suppliers_purchasing' => (int) env('HELPSCOUT_MAILBOX_SUPPLIERS_PURCHASING'),
        'accounts' => (int) env('HELPSCOUT_MAILBOX_ACCOUNTS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Settings
    |--------------------------------------------------------------------------
    */
    'timeout_seconds' => 30,
    'retry_attempts' => 3,

    /*
    |--------------------------------------------------------------------------
    | Tags
    |--------------------------------------------------------------------------
    |
    | HelpScout tags used for conversation filtering.
    |
    */
    'negative_reviews_tag' => 'negative-review',

    /*
    |--------------------------------------------------------------------------
    | System User
    |--------------------------------------------------------------------------
    |
    | HelpScout user ID for automated actions (e.g., adding internal notes).
    | Get this from: Help Scout → Manage → Users → Click user → ID in URL
    |
    */
    'system_user_id' => (int) env('HELPSCOUT_SYSTEM_USER_ID', 0),

    /*
    |--------------------------------------------------------------------------
    | Email Aliases
    |--------------------------------------------------------------------------
    |
    | Maps authenticated user emails to HelpScout account emails.
    | Use when JWT/auth email differs from HelpScout registration.
    |
    */
    'email_aliases' => (static function (): array {
        $from = (string) env('EMAIL_TOM_SECONDARY', '');
        $to = (string) env('EMAIL_TOM_MAIN', '');

        return $from !== '' && $to !== '' ? [$from => $to] : [];
    })(),

    'auth' => [
        /*
        |--------------------------------------------------------------------------
        | Authentication Type
        |--------------------------------------------------------------------------
        |
        | The SDK will allow you to use either legacy credentials for apps created
        | using the Mailbox API v1 or the client credentials grant for apps that
        | were created using the Mailbox API v2. Valid values for this field
        | are `client_credentials`, `legacy_token`, or simply null.
        |
        */
        'type' => env('HELPSCOUT_AUTH_TYPE'),

        /*
        |--------------------------------------------------------------------------
        | Application ID
        |--------------------------------------------------------------------------
        |
        | Get this value from the `/users/apps/{userId}/{appSlug}` page within
        | the Help Scout UI. This field is required if you are using the
        | `client_credentials` grant.
        |
        */
        'appId' => env('HELPSCOUT_APP_ID', ''),

        /*
        |--------------------------------------------------------------------------
        | Application Secret
        |--------------------------------------------------------------------------
        |
        | Get this value from the My Apps page within the Help Scout UI. This field
        | is required if you are using the `client_credentials` grant.
        |
        */
        'appSecret' => env('HELPSCOUT_APP_SECRET', ''),

        /*
        |--------------------------------------------------------------------------
        | Legacy Client ID
        |--------------------------------------------------------------------------
        |
        | Get this value from the `/users/apps/{userId}/{appSlug}` page within
        | the Help Scout UI in the "App ID" field. This field is required if
        | use are using the `legacy_token` auth credentials
        |
        */
        'clientId' => env('HELPSCOUT_CLIENT_ID', ''),

        /*
        |--------------------------------------------------------------------------
        | Legacy API Key
        |--------------------------------------------------------------------------
        |
        | Get this value from the `/users/authentication/{userId}/api-keys` page
        | within the Help Scout UI. This field is required if you are using
        | the `legacy_token` auth credentials.
        |
        */
        'apiKey' => env('HELPSCOUT_API_KEY', ''),
    ],

];
