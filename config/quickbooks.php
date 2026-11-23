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
    | Verify runs share one sandbox company, so each run labels its documents
    | and customers; `verify up --qbo-sandbox` sets both. Blank everywhere else.
    */
    'doc_number_prefix' => env('QBO_RUN_DOC_NUMBER_PREFIX', ''),
    'customer_name_suffix' => env('QBO_RUN_CUSTOMER_NAME_SUFFIX', ''),
];
