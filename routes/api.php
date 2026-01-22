<?php

declare(strict_types=1);

use App\Domain\Access\ValueObjects\AuthenticatedUser;
use App\Infrastructure\Sentry\SentryUserContextMiddleware;
use App\Presentation\Http\Auth\Middleware\ValidateSupabaseJwtMiddleware;
use App\Presentation\Http\Controllers\HelpScoutController;
use App\Presentation\Http\Middleware\HandleHelpScoutExceptionsMiddleware;
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

Route::middleware([
    'throttle:api',
    ValidateSupabaseJwtMiddleware::class,
    SentryUserContextMiddleware::class, // Must be AFTER JWT middleware
])->group(static function (): void {

    // Test route to verify authentication is working
    Route::get('user', static fn(AuthenticatedUser $user): array => [
        'user_id' => $user->id,
        'email' => $user->email,
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
    Route::prefix('helpscout')->middleware(HandleHelpScoutExceptionsMiddleware::class)->group(static function (): void {
        Route::prefix('conversations')->group(static function (): void {
            Route::get('assigned', [HelpScoutController::class, 'assigned']);
            Route::post('assigned/refresh', [HelpScoutController::class, 'refreshAssigned']);

            Route::get('todos', [HelpScoutController::class, 'todos']);
            Route::post('todos/refresh', [HelpScoutController::class, 'refreshTodos']);

            Route::get('negative-reviews', [HelpScoutController::class, 'negativeReviews']);
            Route::post('negative-reviews/refresh', [HelpScoutController::class, 'refreshNegativeReviews']);

            Route::get('escalations', [HelpScoutController::class, 'escalations']);
            Route::post('escalations/refresh', [HelpScoutController::class, 'refreshEscalations']);
        });

        /*
        |--------------------------------------------------------------------------
        | HelpScout User Endpoints
        |--------------------------------------------------------------------------
        |
        | User identity and connection status for settings page.
        |
        */
        Route::prefix('user')->group(static function (): void {
            Route::get('profile', [HelpScoutController::class, 'profile']);
        });
    });
});
