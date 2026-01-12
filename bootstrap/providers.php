<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\BingAdsServiceProvider;
use App\Providers\CacheServiceProvider;
use App\Providers\DatabaseServiceProvider;
use App\Providers\GoogleAdsServiceProvider;
use App\Providers\HelpScoutServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\LinnworksServiceProvider;
use App\Providers\MixpanelServiceProvider;
use App\Providers\ProductSearchFeedServiceProvider;
use App\Providers\ReviewsIoServiceProvider;
use App\Providers\RlsDatabaseServiceProvider;
use App\Providers\ShopwiredServiceProvider;
use App\Providers\StorageServiceProvider;
use App\Providers\TelescopeServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    BingAdsServiceProvider::class,
    CacheServiceProvider::class,
    GoogleAdsServiceProvider::class,
    HelpScoutServiceProvider::class,
    LinnworksServiceProvider::class,
    MixpanelServiceProvider::class,
    ProductSearchFeedServiceProvider::class,
    ReviewsIoServiceProvider::class,
    RlsDatabaseServiceProvider::class,
    ShopwiredServiceProvider::class,
    StorageServiceProvider::class,
    DatabaseServiceProvider::class,
    HorizonServiceProvider::class,
    ...app()->environment('local') ? [TelescopeServiceProvider::class] : [],
];
