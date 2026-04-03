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
        $this->registerHttpLimiters();
        $this->registerQueueLimiters();
    }

    private function registerHttpLimiters(): void
    {
        RateLimiter::for('api', static function (Request $request): Limit {
            $user = $request->user();

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

        RateLimiter::for('webhooks', static fn(Request $request): Limit => Limit::perMinute(300)->by($request->ip()));
        RateLimiter::for('global', static fn(Request $request): Limit => Limit::perMinute(120)->by($request->ip()));
        RateLimiter::for('contact-form', static fn(Request $request): Limit => Limit::perMinute(5)->by($request->ip()));
    }

    private function registerQueueLimiters(): void
    {
        RateLimiter::for('shopwired-api', static fn(): Limit => Limit::perMinute(55));
        RateLimiter::for('shopwired-api-bulk', static fn(): Limit => Limit::perMinute(20));
    }
}
