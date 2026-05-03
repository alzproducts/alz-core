<?php

declare(strict_types=1);

use App\Domain\Access\ValueObjects\AuthenticatedUser;
use App\Infrastructure\Sentry\SentryUserContextMiddleware;
use App\Presentation\Http\Api\Controllers\BrandController;
use App\Presentation\Http\Api\Controllers\BrandUpdateController;
use App\Presentation\Http\Api\Controllers\CategoryController;
use App\Presentation\Http\Api\Controllers\CategoryUpdateController;
use App\Presentation\Http\Api\Controllers\ClickUp\ClickUpAuthController;
use App\Presentation\Http\Api\Controllers\ClickUp\ClickUpTaskController;
use App\Presentation\Http\Api\Controllers\CustomFieldDefinitionController;
use App\Presentation\Http\Api\Controllers\CustomFieldGeneralSettingsController;
use App\Presentation\Http\Api\Controllers\CustomFieldProductSettingsController;
use App\Presentation\Http\Api\Controllers\FilterGroupController;
use App\Presentation\Http\Api\Controllers\ProductController;
use App\Presentation\Http\Api\Controllers\ProductPricingUpdateController;
use App\Presentation\Http\Api\Controllers\ProductRefreshController;
use App\Presentation\Http\Api\Controllers\ProductUpdateController;
use App\Presentation\Http\Auth\Middleware\ValidateSupabaseJwtMiddleware;
use App\Presentation\Http\Controllers\ContactForm\ContactFormController;
use App\Presentation\Http\Controllers\HelpScout\ConversationsController;
use App\Presentation\Http\Controllers\HelpScout\ProfileController;
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
    Route::prefix('helpscout')->middleware([HandleHelpScoutExceptionsMiddleware::class, EnsureUserApprovedMiddleware::class])->group(static function (): void {
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
        // Product endpoints
        Route::get('products', [ProductController::class, 'index']);
        Route::get('products/{productId}', [ProductController::class, 'show'])
            ->whereNumber('productId');
        Route::put('products/{productId}', [ProductUpdateController::class, 'updateFields'])
            ->whereNumber('productId');
        Route::get('products/{productId}/custom-fields', [ProductController::class, 'customFields'])
            ->whereNumber('productId');
        Route::put('products/{productId}/custom-fields', [ProductUpdateController::class, 'updateCustomFields'])
            ->whereNumber('productId');
        Route::put('products/{productId}/prices', [ProductPricingUpdateController::class, 'updatePrices'])
            ->whereNumber('productId');
        Route::post('products/refresh', [ProductRefreshController::class, 'refreshAll']);
        Route::post('products/{productId}/refresh', [ProductRefreshController::class, 'refresh'])
            ->whereNumber('productId');
        Route::post('products/{productId}/generate-variant-skus', [ProductUpdateController::class, 'generateVariantSkus'])
            ->whereNumber('productId');
        Route::post('products/free-delivery', [ProductUpdateController::class, 'updateFreeDelivery']);
        Route::put('products/cost-prices', [ProductPricingUpdateController::class, 'updateCostPrices']);

        // Category endpoints
        Route::get('categories', [CategoryController::class, 'index']);
        Route::get('categories/{categoryId}', [CategoryController::class, 'show'])
            ->whereNumber('categoryId');
        Route::put('categories/{categoryId}', [CategoryUpdateController::class, 'updateFields'])
            ->whereNumber('categoryId');
        Route::get('categories/{categoryId}/custom-fields', [CategoryController::class, 'customFields'])
            ->whereNumber('categoryId');
        Route::put('categories/{categoryId}/custom-fields', [CategoryUpdateController::class, 'updateCustomFields'])
            ->whereNumber('categoryId');
        Route::post('categories/refresh', [CategoryUpdateController::class, 'refreshAll']);
        Route::post('categories/{categoryId}/refresh', [CategoryUpdateController::class, 'refresh'])
            ->whereNumber('categoryId');

        // Brand endpoints
        Route::get('brands', [BrandController::class, 'index']);
        Route::get('brands/{brandId}', [BrandController::class, 'show'])
            ->whereNumber('brandId');
        Route::put('brands/{brandId}', [BrandUpdateController::class, 'updateFields'])
            ->whereNumber('brandId');
        Route::get('brands/{brandId}/custom-fields', [BrandController::class, 'customFields'])
            ->whereNumber('brandId');
        Route::put('brands/{brandId}/custom-fields', [BrandUpdateController::class, 'updateCustomFields'])
            ->whereNumber('brandId');
        Route::post('brands/refresh', [BrandUpdateController::class, 'refreshAll']);
        Route::post('brands/{brandId}/refresh', [BrandUpdateController::class, 'refresh'])
            ->whereNumber('brandId');

        Route::get('filter-groups', [FilterGroupController::class, 'index']);

        // ClickUp endpoints
        Route::prefix('clickup')->group(static function (): void {
            Route::post('api-key', [ClickUpAuthController::class, 'save']);
            Route::get('api-key', [ClickUpAuthController::class, 'info']);
            Route::delete('api-key', [ClickUpAuthController::class, 'delete']);

            Route::get('tasks', [ClickUpTaskController::class, 'index']);
            Route::post('tasks/{taskId}/complete', [ClickUpTaskController::class, 'complete']);
        });

        // Custom field definition endpoints (catalog)
        Route::get('catalog/custom-field-definitions', [CustomFieldDefinitionController::class, 'index']);
        Route::get('catalog/custom-field-definitions/{definitionId}', [CustomFieldDefinitionController::class, 'show'])
            ->whereNumber('definitionId');
        Route::put('catalog/custom-field-definitions/{definitionUuid}/general-settings', CustomFieldGeneralSettingsController::class)
            ->whereUuid('definitionUuid');
        Route::put('catalog/custom-field-definitions/{definitionUuid}/product-settings', CustomFieldProductSettingsController::class)
            ->whereUuid('definitionUuid');
    });
