<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | QuickBooks Online (QBO) Connection
    |--------------------------------------------------------------------------
    |
    | One credential set per deployment. `environment` is exactly `sandbox` or
    | `production`; production keys exist only on the production deployment,
    | and any non-production APP_ENV refuses `production`.
    |
    */

    'environment' => env('QUICKBOOKS_ENVIRONMENT'),
    'client_id' => env('QUICKBOOKS_CLIENT_ID'),
    'client_secret' => env('QUICKBOOKS_CLIENT_SECRET'),
    'redirect_uri' => env('QUICKBOOKS_REDIRECT_URI'),
    'realm_id' => env('QUICKBOOKS_REALM_ID'),

    /*
    | The OAuth state and the plaintext access token live in this store; the
    | default `database` store would put the access token in Postgres.
    */
    'cache_store' => env('QUICKBOOKS_CACHE_STORE', 'redis'),

    /*
    | Sandbox-only labels that keep test documents and customers apart between
    | runs. Blank is valid; a label outside the sandbox environment is refused.
    */
    'doc_number_prefix' => env('QBO_RUN_DOC_NUMBER_PREFIX', ''),
    'customer_name_suffix' => env('QBO_RUN_CUSTOMER_NAME_SUFFIX', ''),

    /*
    | Orders placed before this date (YYYY-MM-DD, from 00:00 Europe/London) are
    | never synced. While it is unset, every sync request is refused.
    */
    'backfill_floor_date' => env('QUICKBOOKS_BACKFILL_FLOOR_DATE'),
];
