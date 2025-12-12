<?php

declare(strict_types=1);

use App\Presentation\Http\Controllers\HelpScoutController;
use App\Presentation\Http\Middleware\ValidateSupabaseJwtMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| All routes here are protected by Supabase JWT authentication.
| Each request must include a valid Bearer token in the Authorization header.
|
| Rate limiting: 60 requests per minute per authenticated user
|
*/

Route::middleware(['throttle:api', ValidateSupabaseJwtMiddleware::class])->group(static function (): void {

    // Test route to verify authentication is working
    Route::get('/user', static fn(Request $request): array => [
        'user_id' => $request->input('auth_user_id'),
        'email' => $request->input('auth_user_email'),
    ]);

    /*
    |--------------------------------------------------------------------------
    | HelpScout Endpoints
    |--------------------------------------------------------------------------
    |
    | Dashboard widget APIs for customer service conversations.
    | GET endpoints return cached data; POST /refresh invalidates + fetches fresh.
    |
    */
    Route::prefix('helpscout/conversations')->group(static function (): void {
        Route::get('/assigned', [HelpScoutController::class, 'assigned']);
        Route::post('/assigned/refresh', [HelpScoutController::class, 'refreshAssigned']);

        Route::get('/todos', [HelpScoutController::class, 'todos']);
        Route::post('/todos/refresh', [HelpScoutController::class, 'refreshTodos']);

        Route::get('/negative-reviews', [HelpScoutController::class, 'negativeReviews']);
        Route::post('/negative-reviews/refresh', [HelpScoutController::class, 'refreshNegativeReviews']);
    });
});
