<?php

declare(strict_types=1);

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
    // @phpstan-ignore-next-line shipmonk.checkedExceptionInCallable (Laravel route closures are framework-managed; exceptions handled by exception handler)
    Route::get('/user', static fn(Request $request): array => [
        'user_id' => $request->input('auth_user_id'),
        'email' => $request->input('auth_user_email'),
    ]);

    // Future API endpoints will be added here
    // Example:
    // Route::post('/webhooks/shopify', [ShopifyWebhookController::class, 'handle']);
    // Route::get('/orders', [OrderController::class, 'index']);
    // Route::post('/sync/products', [ProductSyncController::class, 'sync']);
});
