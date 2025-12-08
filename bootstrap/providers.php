<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\GoogleAdsServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\LinnworksServiceProvider;
use App\Providers\MixpanelServiceProvider;
use App\Providers\ProductSearchFeedServiceProvider;
use App\Providers\ReviewsIoServiceProvider;
use App\Providers\ShopwiredServiceProvider;
use App\Providers\StorageServiceProvider;
use App\Providers\TelescopeServiceProvider;

return [
    AppServiceProvider::class,
    GoogleAdsServiceProvider::class,
    LinnworksServiceProvider::class,
    MixpanelServiceProvider::class,
    ProductSearchFeedServiceProvider::class,
    ReviewsIoServiceProvider::class,
    ShopwiredServiceProvider::class,
    StorageServiceProvider::class,
    HorizonServiceProvider::class,
    ...app()->environment('local') ? [TelescopeServiceProvider::class] : [],
];
