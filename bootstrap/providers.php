<?php

declare(strict_types=1);
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\ReviewsIoServiceProvider;
use App\Providers\TelescopeServiceProvider;

return [
    AppServiceProvider::class,
    ReviewsIoServiceProvider::class,
    HorizonServiceProvider::class,
    ...app()->environment('local') ? [TelescopeServiceProvider::class] : [],
];
