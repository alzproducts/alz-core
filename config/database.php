<?php

declare(strict_types=1);

use Illuminate\Support\Str;

// Base PostgreSQL config shared across all connections
// All use standard 'pgsql' driver; RLS enforcement via beforeExecuting callbacks in RlsDatabaseServiceProvider
$basePostgres = [
    'url' => env('DB_URL'),
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '5432'),
    'database' => env('DB_DATABASE', 'laravel'),
    'username' => env('DB_USERNAME', 'root'),
    'password' => env('DB_PASSWORD', ''),
    'charset' => env('DB_CHARSET', 'utf8'),
    'prefix' => '',
    'prefix_indexes' => true,
    // Multi-schema search path for Supabase database
    // Tables are organized across schemas: public (profiles), access (roles/permissions), config (dashboard), utils (helpers)
    // Note: Tables in non-public schemas still need explicit schema prefix in Eloquent models (e.g., 'access.roles')
    // The search_path primarily helps with unqualified function calls and type resolution
    'search_path' => 'public,access,config,utils',
    'sslmode' => env('DB_SSLMODE', 'require'),
];

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    // Default to RLS-enforced connection for security-by-default
    // API routes automatically get user-scoped data access
    // Use DB::connection('pgsql_admin') explicitly for service/admin operations
    'default' => env('DB_CONNECTION', 'pgsql_rls'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        // Standard PostgreSQL connection (no RLS context management)
        // Used for migrations, seeders, and framework operations
        'pgsql' => ['driver' => 'pgsql', ...$basePostgres],

        // RLS-enforced: requires user context set by SetRlsContextMiddleware
        // beforeExecuting callback throws if Context::get('rls_user_id') is missing
        // Use for user-initiated requests where data should be scoped to the authenticated user
        'pgsql_rls' => ['driver' => 'pgsql', ...$basePostgres],

        // Admin connection: BYPASSES RLS - use only for service/admin operations
        // beforeExecuting callback clears any stale session variables (Octane safety)
        // WARNING: All queries have unrestricted access to data across all users
        'pgsql_admin' => ['driver' => 'pgsql', ...$basePostgres],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'connection' => 'pgsql', // Migrations don't need RLS context; use raw connection
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')) . '-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'read_timeout' => 0, // Unlimited — prevents phpredis socket timeout during long-running jobs
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
