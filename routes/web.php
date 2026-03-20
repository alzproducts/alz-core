<?php

declare(strict_types=1);

use App\Presentation\Http\Controllers\FeedController;
use App\Presentation\Http\Controllers\QueueHealthController;
use App\Presentation\Http\Middleware\HorizonBasicAuthMiddleware;
use Illuminate\Support\Facades\Route;

// Health check is configured in bootstrap/app.php via health: '/up'
// No need to define it here - Laravel 12 handles it at the framework level

/*
|--------------------------------------------------------------------------
| Feed Routes
|--------------------------------------------------------------------------
|
| Product feed endpoints for external consumers (DooFinder, etc.).
| URLs use prefix + GUID for obscurity while remaining human-readable.
|
*/

Route::get('feeds/{prefix}-{guid}.xml', [FeedController::class, 'show'])
    ->name('feeds.show')
    ->where(['prefix' => '[a-z0-9]+', 'guid' => '[a-f0-9]{32}']);

/*
|--------------------------------------------------------------------------
| Operations Routes (BasicAuth Protected)
|--------------------------------------------------------------------------
|
| Internal endpoints for monitoring and ops dashboards.
| Same credentials as Horizon dashboard.
|
*/

Route::get('ops/queue-health', QueueHealthController::class)
    ->middleware(HorizonBasicAuthMiddleware::class)
    ->name('ops.queue-health');
