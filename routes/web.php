<?php

declare(strict_types=1);

use App\Presentation\Http\Controllers\FeedController;
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

Route::get('/feeds/{prefix}-{guid}.xml', [FeedController::class, 'show'])
    ->name('feeds.show')
    ->where(['prefix' => '[a-z0-9]+', 'guid' => '[a-f0-9]{32}']);

// Admin routes (Horizon/Telescope) will be added here later
