<?php

declare(strict_types=1);

// intentional: config loads before providers, so this reads the env directly instead of LiveIntegrationGate.
$remoteStorageLive = env('APP_ENV') === 'production'
    || filter_var(env('REMOTE_STORAGE_LIVE', false), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === true;

return [

    /*
    |--------------------------------------------------------------------------
    | DooFinder Product Feed
    |--------------------------------------------------------------------------
    |
    | Configuration for the DooFinder site search feed processor.
    | Transforms Google Ads product feed by substituting <title> with <d_title>.
    |
    */

    'doofinder' => [
        'source_url' => env('FEEDS_DOOFINDER_SOURCE_URL'),
        'storage_disk' => $remoteStorageLive ? env('FEEDS_STORAGE_DISK', 's3') : 'local',
        'storage_path' => 'feeds/doofinder-processed.xml',
        'public_prefix' => 'doofinder',
        'public_guid' => env('DOOFINDER_FEED_GUID'),
        'signed_url_expiry_minutes' => 1440, // 24 hours - allows for crawler caching
    ],

];
