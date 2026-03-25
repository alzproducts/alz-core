<?php

declare(strict_types=1);

use App\Domain\Access\ValueObjects\AuthenticatedUser;
use App\Infrastructure\Sentry\SentryUserContextMiddleware;
use App\Presentation\Http\Api\Controllers\ProductController;
use App\Presentation\Http\Auth\Middleware\ValidateSupabaseJwtMiddleware;
use App\Presentation\Http\Controllers\ContactForm\ContactFormController;
use App\Presentation\Http\Controllers\HelpScout\ConversationsController;
use App\Presentation\Http\Controllers\HelpScout\ProfileController;
use App\Presentation\Http\Controllers\Shopwired\ProductUpdateController;
use App\Presentation\Http\Controllers\Shopwired\Webhooks\ShopwiredWebhookBrandController;
use App\Presentation\Http\Controllers\Shopwired\Webhooks\ShopwiredWebhookCategoryController;
use App\Presentation\Http\Controllers\Shopwired\Webhooks\ShopwiredWebhookCustomerController;
use App\Presentation\Http\Controllers\Shopwired\Webhooks\ShopwiredWebhookOrderController;
use App\Presentation\Http\Controllers\Shopwired\Webhooks\ShopwiredWebhookProductController;
use App\Presentation\Http\HelpScout\Middleware\DetectRefreshMiddleware;
use App\Presentation\Http\HelpScout\Middleware\HandleHelpScoutExceptionsMiddleware;
use App\Presentation\Http\Middleware\EnsureUserApprovedMiddleware;
use App\Presentation\Http\Middleware\RejectHoneypotMiddleware;
use App\Presentation\Http\Middleware\VerifyShopwiredWebhookSignatureMiddleware;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public API Routes (No Authentication)
|--------------------------------------------------------------------------
|
| These routes are publicly accessible for external form submissions.
| Protected by rate limiting and honeypot spam detection.
|
*/

Route::middleware([
    'throttle:contact-form',
    RejectHoneypotMiddleware::class,
])->group(static function (): void {
    Route::post('contact', ContactFormController::class);
});

/*
|--------------------------------------------------------------------------
| ShopWired Webhook Routes
|--------------------------------------------------------------------------
|
| Authenticated via HMAC signature verification (not JWT).
| VerifyShopwiredWebhookSignatureMiddleware validates the X-ShopWired-Signature header.
|
*/

Route::prefix('webhooks/shopwired')
    ->middleware(['throttle:webhooks', VerifyShopwiredWebhookSignatureMiddleware::class])
    ->group(static function (): void {
        Route::post('orders', ShopwiredWebhookOrderController::class);
        Route::post('products', ShopwiredWebhookProductController::class);
        Route::post('customers', ShopwiredWebhookCustomerController::class);
        Route::post('categories', ShopwiredWebhookCategoryController::class);
        Route::post('brands', ShopwiredWebhookBrandController::class);
    });

/*
|--------------------------------------------------------------------------
| Authenticated API Routes
|--------------------------------------------------------------------------
|
| All routes below are protected by Supabase JWT authentication.
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
    | GET returns cached data; POST invalidates cache + fetches fresh.
    | DetectRefreshMiddleware converts HTTP verb to forceRefresh attribute.
    |
    */
    Route::prefix('helpscout')->middleware(HandleHelpScoutExceptionsMiddleware::class)->group(static function (): void {
        Route::prefix('conversations')->middleware(DetectRefreshMiddleware::class)->group(static function (): void {
            Route::match(['get', 'post'], 'assigned', [ConversationsController::class, 'assigned']);
            Route::match(['get', 'post'], 'todos', [ConversationsController::class, 'todos']);
            Route::match(['get', 'post'], 'negative-reviews', [ConversationsController::class, 'negativeReviews']);
            Route::match(['get', 'post'], 'escalations', [ConversationsController::class, 'escalations']);
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
            Route::get('profile', ProfileController::class);
        });
    });

    /*
    |--------------------------------------------------------------------------
    | ShopWired Endpoints
    |--------------------------------------------------------------------------
    |
    | Product management APIs for ShopWired integration.
    |
    */
    Route::prefix('shopwired')->group(static function (): void {
        Route::prefix('products')->group(static function (): void {
            Route::post('free-delivery', [ProductUpdateController::class, 'updateFreeDelivery']);
            Route::post('{productId}/prices', [ProductUpdateController::class, 'updatePrices'])
                ->whereNumber('productId');
        });
    });
});

/*
|--------------------------------------------------------------------------
| Consumer API Routes (Supabase Auth + Approval Gate)
|--------------------------------------------------------------------------
|
| Domain-centric endpoints for the frontend application.
| Auth: JWT + approval check (no RLS context — see issue for RLS design).
|
*/

Route::middleware([ValidateSupabaseJwtMiddleware::class, EnsureUserApprovedMiddleware::class, 'throttle:api', SentryUserContextMiddleware::class])
    ->group(static function (): void {
        Route::get('products', [ProductController::class, 'index']);
        Route::get('products/{productId}', [ProductController::class, 'show'])
            ->whereNumber('productId');
    });
