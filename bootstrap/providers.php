<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\BingAdsServiceProvider;
use App\Providers\CacheServiceProvider;
use App\Providers\CatalogServiceProvider;
use App\Providers\ClickUpServiceProvider;
use App\Providers\ContactSubmissionServiceProvider;
use App\Providers\ConversionServiceProvider;
use App\Providers\DatabaseServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\GoogleAdsServiceProvider;
use App\Providers\HelpScoutServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\InventoryServiceProvider;
use App\Providers\LinnworksServiceProvider;
use App\Providers\MixpanelServiceProvider;
use App\Providers\NotificationServiceProvider;
use App\Providers\ProductSearchFeedServiceProvider;
use App\Providers\QueueObservabilityServiceProvider;
use App\Providers\RateLimitServiceProvider;
use App\Providers\ReviewsIoServiceProvider;
use App\Providers\RlsDatabaseServiceProvider;
use App\Providers\Schedule\AdsScheduleServiceProvider;
use App\Providers\Schedule\CatalogScheduleServiceProvider;
use App\Providers\Schedule\ContactFormScheduleServiceProvider;
use App\Providers\Schedule\FeedsScheduleServiceProvider;
use App\Providers\Schedule\InventoryScheduleServiceProvider;
use App\Providers\Schedule\LinnworksScheduleServiceProvider;
use App\Providers\Schedule\MixpanelScheduleServiceProvider;
use App\Providers\Schedule\QueueMaintenanceScheduleServiceProvider;
use App\Providers\Schedule\ReviewsIoScheduleServiceProvider;
use App\Providers\Schedule\ShopwiredScheduleServiceProvider;
use App\Providers\ShopwiredServiceProvider;
use App\Providers\StorageServiceProvider;
use App\Providers\TelescopeServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    BingAdsServiceProvider::class,
    CacheServiceProvider::class,
    ClickUpServiceProvider::class,
    CatalogServiceProvider::class,
    ContactSubmissionServiceProvider::class,
    ConversionServiceProvider::class,
    GoogleAdsServiceProvider::class,
    HelpScoutServiceProvider::class,
    LinnworksServiceProvider::class,
    MixpanelServiceProvider::class,
    NotificationServiceProvider::class,
    QueueObservabilityServiceProvider::class,
    ProductSearchFeedServiceProvider::class,
    RateLimitServiceProvider::class,
    ReviewsIoServiceProvider::class,
    RlsDatabaseServiceProvider::class,
    ShopwiredServiceProvider::class,
    StorageServiceProvider::class,
    DatabaseServiceProvider::class,
    EventServiceProvider::class,
    HorizonServiceProvider::class,
    InventoryServiceProvider::class,

    // Schedule providers (must not be deferred - schedules register at boot)
    AdsScheduleServiceProvider::class,
    CatalogScheduleServiceProvider::class,
    ContactFormScheduleServiceProvider::class,
    FeedsScheduleServiceProvider::class,
    LinnworksScheduleServiceProvider::class,
    MixpanelScheduleServiceProvider::class,
    QueueMaintenanceScheduleServiceProvider::class,
    ReviewsIoScheduleServiceProvider::class,
    InventoryScheduleServiceProvider::class,
    ShopwiredScheduleServiceProvider::class,

    ...app()->environment('local') ? [TelescopeServiceProvider::class] : [],
];
