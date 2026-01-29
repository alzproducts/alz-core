<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Register rate limiters for API endpoints.
 *
 * Defined in a service provider (not bootstrap/app.php) to ensure
 * proper registration during Octane worker warmup.
 */
final class RateLimitServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void {}

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // API rate limiter: 60 requests per minute per user/IP
        RateLimiter::for('api', static function (Request $request): Limit {
            $user = $request->user();

            // Rate limit by user ID if authenticated, otherwise by IP
            if ($user !== null) {
                $identifier = $user->getAuthIdentifier();
                $rateLimitKey = \is_string($identifier) || \is_int($identifier)
                    ? (string) $identifier
                    : $request->ip();
            } else {
                $rateLimitKey = $request->ip();
            }

            return Limit::perMinute(60)->by($rateLimitKey);
        });

        // Webhooks rate limiter: 100 requests per minute per IP
        RateLimiter::for('webhooks', static fn(Request $request): Limit => Limit::perMinute(100)->by($request->ip()));

        // Global rate limiter: 120 requests per minute per IP
        RateLimiter::for('global', static fn(Request $request): Limit => Limit::perMinute(120)->by($request->ip()));
    }
}
