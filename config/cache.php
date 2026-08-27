<?php

declare(strict_types=1);

use App\Domain\CustomerService\ValueObjects\Conversation;
use App\Domain\CustomerService\ValueObjects\ConversationAssignee;
use App\Domain\CustomerService\ValueObjects\ConversationCustomer;
use App\Domain\CustomerService\ValueObjects\ConversationSnooze;
use App\Domain\CustomerService\ValueObjects\ConversationTag;
use App\Domain\CustomerService\ValueObjects\EscalationsConfig;
use App\Domain\CustomerService\ValueObjects\Mailbox;
use App\Domain\CustomerService\ValueObjects\SupportAgent;
use App\Infrastructure\BingAds\BingAdsSession;
use App\Infrastructure\Linnworks\LinnworksSession;
use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | This option controls the default cache store that will be used by the
    | framework. This connection is utilized if another isn't explicitly
    | specified when running a cache operation inside the application.
    |
    */

    'default' => env('CACHE_STORE', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the cache "stores" for your application as
    | well as their drivers. You may even define multiple stores for the
    | same cache driver to group types of items stored in your caches.
    |
    | Supported drivers: "array", "database", "file", "memcached",
    |                    "redis", "dynamodb", "octane", "null"
    |
    */

    'stores' => [

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_CACHE_CONNECTION'),
            'table' => env('DB_CACHE_TABLE', 'cache'),
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
            'lock_table' => env('DB_CACHE_LOCK_TABLE'),
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
            'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
        ],

        'octane' => [
            'driver' => 'octane',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | When utilizing the APC, database, memcached, Redis, and DynamoDB cache
    | stores, there might be other applications using the same cache. For
    | that reason, you may prefix every cache key to avoid collisions.
    |
    */

    'prefix' => env('CACHE_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')) . '-cache-'),

    /*
    |--------------------------------------------------------------------------
    | Cache Serializable Classes
    |--------------------------------------------------------------------------
    |
    | Allow-list passed to unserialize(allowed_classes:) by every serializing
    | store. The list is global — it must cover every class any cache writer
    | stores, nested ones included. An omitted class is not reported at write
    | time: it comes back as __PHP_Incomplete_Class and fails only on read.
    |
    | Guarded by tests/Feature/Infrastructure/Cache/SerializableClassesRoundTripTest.php
    |
    */

    'serializable_classes' => [
        // Root cached objects
        BingAdsSession::class,
        LinnworksSession::class,
        SupportAgent::class,
        EscalationsConfig::class,
        Conversation::class,
        Mailbox::class,
        // Nested inside the roots' serialized payloads — the allow-list filters these too
        DateTimeImmutable::class,
        ConversationSnooze::class,
        ConversationTag::class,
        ConversationCustomer::class,
        ConversationAssignee::class,
    ],

];
