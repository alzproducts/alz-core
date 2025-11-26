<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\GoogleAdsServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\MixpanelServiceProvider;
use App\Providers\ReviewsIoServiceProvider;
use App\Providers\ShopwiredServiceProvider;
use App\Providers\TelescopeServiceProvider;

return [
    AppServiceProvider::class,
    GoogleAdsServiceProvider::class,
    MixpanelServiceProvider::class,
    ReviewsIoServiceProvider::class,
    ShopwiredServiceProvider::class,
    HorizonServiceProvider::class,
    ...app()->environment('local') ? [TelescopeServiceProvider::class] : [],
];
