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

        // Webhooks rate limiter: 300 requests per minute per IP
        // Increased from 100 to accommodate ShopWired webhook bursts (e.g. bulk order updates)
        RateLimiter::for('webhooks', static fn(Request $request): Limit => Limit::perMinute(300)->by($request->ip()));

        // Global rate limiter: 120 requests per minute per IP
        RateLimiter::for('global', static fn(Request $request): Limit => Limit::perMinute(120)->by($request->ip()));

        // Contact form rate limiter: 5 requests per minute per IP
        // Prevents spam floods while allowing legitimate resubmissions after validation errors
        RateLimiter::for('contact-form', static fn(Request $request): Limit => Limit::perMinute(5)->by($request->ip()));

        // Queue rate limiters: proactive rate limiting for outbound API calls
        // These run in queue worker context (no Request object) — use static closures

        // ShopWired API: 55 req/min (leaves headroom below actual limit)
        RateLimiter::for('shopwired-api', static fn(): Limit => Limit::perMinute(55));

        // ShopWired API bulk: 30 req/min for high-volume single-item jobs (e.g. free delivery updates)
        RateLimiter::for('shopwired-api-bulk', static fn(): Limit => Limit::perMinute(30));
    }
}
