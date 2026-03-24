<?php

declare(strict_types=1);

use App\Presentation\Http\Api\InternalApiExceptionMapper;
use App\Presentation\Http\Auth\Middleware\ValidateSupabaseJwtMiddleware;
use App\Presentation\Http\Middleware\EnsureUserApprovedMiddleware;
use App\Presentation\Http\Middleware\SetRequestContextMiddleware;
use App\Presentation\Http\Middleware\SetRlsContextMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withCommands([
        __DIR__ . '/../app/Presentation/Console/Commands',
    ])
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(static function (Middleware $middleware): void {
        // Trust all proxies for Railway deployment
        // Railway uses a reverse proxy that adds X-Forwarded-* headers
        // We must trust Railway's proxy to properly handle forwarded headers
        // This is safe because Railway's network is isolated and all traffic
        // goes through their proxy - direct access to the container is not possible
        $middleware->trustProxies(at: '*');

        // Correlation IDs: trace_id for all requests, propagates to queued jobs
        $middleware->append(SetRequestContextMiddleware::class);

        // Supabase Auth: JWT validation + user approval check + RLS context
        // Apply to all protected API routes
        $middleware->appendToGroup('auth.supabase', [
            ValidateSupabaseJwtMiddleware::class,
            EnsureUserApprovedMiddleware::class,
            SetRlsContextMiddleware::class, // Sets Context('rls_user_id') for pgsql_rls connection
        ]);
    })
    ->withExceptions(static function (Exceptions $exceptions): void {
        // Hook Sentry into Laravel's exception handler to capture all unhandled exceptions
        Integration::handles($exceptions);

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

        // Universal JSON error envelope for all API routes (consistent shape for frontend)
        $exceptions->render(InternalApiExceptionMapper::render(...));
    })->create();
