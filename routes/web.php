<?php

declare(strict_types=1);

use App\Presentation\Http\Controllers\FeedController;
use App\Presentation\Http\Controllers\QueueHealthController;
use App\Presentation\Http\Middleware\HorizonBasicAuthMiddleware;
use App\Presentation\Http\QuickBooks\Controllers\QuickBooksCallbackController;
use Illuminate\Support\Facades\Route;

// Registered by the `then:` callback in bootstrap/app.php, outside the 'web' middleware
// group — these routes are stateless (no session, cookies, or CSRF).
// The /up health check is configured there too, via health: '/up'.

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

/*
|--------------------------------------------------------------------------
| QuickBooks OAuth Callback (unauthenticated)
|--------------------------------------------------------------------------
|
| Intuit redirects the browser here after consent. The single-use OAuth
| state is the only guard; Sentry scrubs the query (it holds the code).
|
*/

Route::get('quickbooks/callback', QuickBooksCallbackController::class)
    ->middleware('throttle:public-default')
    ->name('quickbooks.callback');
