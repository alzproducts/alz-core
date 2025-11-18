<?php

declare(strict_types=1);

namespace App\Providers;

use App\Infrastructure\Api\ReviewsIoClient;
use Illuminate\Support\ServiceProvider;
use Override;
use RuntimeException;

/**
 * Application Service Provider - Service Registration and Production Validation
 *
 * ## Service Registration Pattern
 *
 * This provider follows a three-layer validation pattern to prevent CI failures:
 *
 * ### 1. Constructor Validation (Infrastructure Layer)
 * Service classes SHOULD validate their own configuration in their constructors.
 * This keeps validation logic close to the service and ensures type safety.
 *
 * Example: ReviewsIoClient validates apiKey, storeId, timeout, retryTimes, retryDelay
 *
 * ### 2. Lazy Resolution (Service Provider)
 * The `register()` method MUST NOT perform eager validation. It only defines how
 * services are constructed. Validation occurs when the service is first resolved.
 *
 * ⚠️ IMPORTANT: `register()` runs during `composer install` and `package:discover`,
 * not just application boot. Eager validation here will fail in CI environments.
 *
 * ### 3. Production Boot Validation (Production Only)
 * The `validateProductionEnvironment()` method checks that critical credentials
 * exist on production boot. This is the safety net for deployment failures.
 *
 * ## When to Use Each Layer
 *
 * | Layer | Use For | Example |
 * |-------|---------|---------|
 * | Constructor | Type validation, range checks, business rules | timeout must be 1-300 seconds |
 * | Lazy Resolution | Type casting mixed config values | `is_string($apiKey) ? $apiKey : ''` |
 * | Production Boot | Critical credentials existence check | APP_KEY, DB_PASSWORD, API keys |
 *
 * ## Example: Registering a New API Client
 *
 * ```php
 * // ✅ CORRECT: Lazy validation, simple registration
 * $this->app->singleton(ExampleClient::class, static function (): ExampleClient {
 *     $apiKey = \config('services.example.api_key');
 *     return new ExampleClient(
 *         apiKey: \is_string($apiKey) ? $apiKey : '', // Constructor validates
 *     );
 * });
 * ```
 *
 * ```php
 * // ❌ WRONG: Eager validation in register()
 * public function register(): void {
 *     $apiKey = \config('services.example.api_key');
 *     if ($apiKey === '') {
 *         throw new RuntimeException('API key missing'); // Fails in CI!
 *     }
 *     // ...
 * }
 * ```
 *
 * @see ReviewsIoClient::__construct() for constructor validation example
 * @see self::validateProductionEnvironment() for production boot validation
 */
final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register application services.
     *
     * ⚠️ CRITICAL: Do NOT add validation logic here. This method runs during
     * `composer install` and will fail in CI environments where secrets are
     * not available.
     *
     * Service constructors handle validation when the service is first resolved.
     */
    #[Override]
    public function register(): void
    {
        $this->registerReviewsIoClient();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Skip validation in CI environments (GitHub Actions, etc.)
        $isCi = (\getenv('CI') !== false) || (\getenv('GITHUB_ACTIONS') !== false);

        if ($this->app->environment('production') && ! $isCi) {
            self::validateProductionEnvironment();
        }
    }

    /**
     * Validate that all required environment variables are configured in production.
     *
     * Prevents catastrophic deployment failures due to missing secrets.
     */
    private static function validateProductionEnvironment(): void
    {
        // Map of config keys to human-readable descriptions
        $required = [
            'app.key' => 'Application encryption key (APP_KEY)',
            'database.connections.pgsql.host' => 'Database host (DB_HOST)',
            'database.connections.pgsql.password' => 'Database password (DB_PASSWORD)',
            'database.redis.default.host' => 'Redis host (REDIS_HOST)',
            'database.redis.default.password' => 'Redis password (REDIS_PASSWORD)',
            'horizon.auth.username' => 'Horizon dashboard username (HORIZON_USER)',
            'horizon.auth.password' => 'Horizon dashboard password (HORIZON_PASSWORD)',
            'services.supabase.jwt_secret' => 'Supabase JWT secret (SUPABASE_JWT_SECRET)',
            'services.reviewsio.api_key' => 'Reviews.io API key (REVIEWSIO_API_KEY)',
            'services.reviewsio.store' => 'Reviews.io store ID (REVIEWSIO_STORE)',
        ];

        $missing = [];

        foreach ($required as $configKey => $description) {
            $value = \config($configKey);

            if (($value === null) || ($value === '') || ($value === false)) {
                $missing[] = $description;
            }
        }

        if ($missing !== []) {
            $list = \implode("\n  - ", $missing);

            throw new RuntimeException(
                "SECURITY: Production deployment blocked. The following required configuration values are not set:\n\n  - {$list}\n\nApplication cannot start safely. Please configure these variables in your deployment environment.",
            );
        }

        // Additional validation: APP_KEY must be properly formatted (base64 encoded)
        $appKey = \config('app.key');
        if (! \is_string($appKey) || (\mb_strlen($appKey) < 32)) {
            throw new RuntimeException(
                "SECURITY: APP_KEY is too short or invalid. Run 'php artisan key:generate' to create a secure key.",
            );
        }
    }

    private function registerReviewsIoClient(): void
    {
        $this->app->singleton(ReviewsIoClient::class, static function (): ReviewsIoClient {
            $apiKey     = \config('services.reviewsio.api_key');
            $store      = \config('services.reviewsio.store');
            $timeout    = \config('services.reviewsio.timeout', 30);
            $retryTimes = \config('services.reviewsio.retry_times', 3);
            $retryDelay = \config('services.reviewsio.retry_delay', 100);

            return new ReviewsIoClient(
                apiKey: \is_string($apiKey) ? $apiKey : '',
                storeId: \is_string($store) ? $store : '',
                timeout: \is_int(
                    $timeout,
                ) ? $timeout : 30,
                retryTimes: \is_int($retryTimes) ? $retryTimes : 3,
                retryDelay: \is_int($retryDelay)
                    ? $retryDelay : 100,
            );
        });
    }

}
