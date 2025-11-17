<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        then: static function (): void {
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
        },
    )
    ->withMiddleware(static function (Middleware $middleware): void {
        // Trust proxies for Railway deployment
        // Railway uses a reverse proxy that adds X-Forwarded-* headers
        // In production: Trust all proxies (*) because Railway's proxy IPs are dynamic
        //                and all traffic is routed through Railway's secure network
        // In local dev: Don't trust any proxies to prevent header spoofing
        $middleware->trustProxies(
            at: app()->environment('production') ? '*' : [],
        );
    })
    ->withExceptions(static function (Exceptions $exceptions): void {
        // Log rate limit violations using render() instead of reportable()
        // because ThrottleRequestsException is thrown in middleware and bypasses reportable callbacks
        $exceptions->render(static function (ThrottleRequestsException $e, Request $request): null {
            Log::channel('security')->warning('Rate limit exceeded', [
                'event' => 'rate_limit.exceeded',
                'ip' => $request->ip(),
                'path' => $request->path(),
                'user_agent' => $request->userAgent(),
            ]);

            // Return null to use Laravel's default 429 response
            return null;
        });
    })->create();
