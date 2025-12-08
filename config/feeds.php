<?php

declare(strict_types=1);

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
        'source_url' => 'https://www.alzproducts.co.uk/feed/products',
        'storage_disk' => env('FEEDS_STORAGE_DISK', 's3'),
        'storage_path' => 'feeds/doofinder-processed.xml',
        'public_prefix' => 'doofinder',
        'public_guid' => env('DOOFINDER_FEED_GUID'),
        'signed_url_expiry_minutes' => 1440, // 24 hours - allows for crawler caching
    ],

];
