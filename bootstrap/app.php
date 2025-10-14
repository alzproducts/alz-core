<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(static function (Middleware $middleware): void {
        // Configure rate limiters for API endpoints
        // @phpstan-ignore-next-line shipmonk.checkedExceptionInCallable (Laravel rate limiter closures are framework-managed; $request->ip() never returns null)
        RateLimiter::for('api', static function (Request $request): Limit {
            $user = $request->user();

            // Rate limit by user ID if authenticated, otherwise by IP
            if ($user !== null) {
                $identifier = $user->getAuthIdentifier();
                $rateLimitKey = (is_string($identifier) || is_int($identifier))
                    ? (string) $identifier
                    : $request->ip();
            } else {
                $rateLimitKey = $request->ip();
            }

            return Limit::perMinute(60)->by($rateLimitKey);
        });

        // @phpstan-ignore-next-line shipmonk.checkedExceptionInCallable (Laravel rate limiter closures are framework-managed; $request->ip() never returns null)
        RateLimiter::for('webhooks', static fn(Request $request): Limit => Limit::perMinute(100)->by($request->ip()));

        // @phpstan-ignore-next-line shipmonk.checkedExceptionInCallable (Laravel rate limiter closures are framework-managed; $request->ip() never returns null)
        RateLimiter::for('global', static fn(Request $request): Limit => Limit::perMinute(120)->by($request->ip()));
    })
    ->withExceptions(static function (Exceptions $exceptions): void {
        //
    })->create();
