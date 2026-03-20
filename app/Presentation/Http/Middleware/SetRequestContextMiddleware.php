<?php

declare(strict_types=1);

namespace App\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets request-scoped correlation IDs for observability.
 *
 * Adds `trace_id` (UUID) and optionally `user_id` to Laravel's Context facade.
 * Context auto-propagates to queued jobs via Laravel's dehydrate/hydrate mechanism,
 * enabling correlation of HTTP requests with their downstream async processing.
 *
 * Runs BEFORE authentication — trace_id is set for all requests, including
 * unauthenticated ones. user_id is added later by SetRlsContextMiddleware
 * (which sets `rls_user_id` after auth resolves the user).
 *
 * @see SetRlsContextMiddleware For user-specific context set after authentication
 */
final class SetRequestContextMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws RuntimeException If UUID generation fails
     */
    public function handle(Request $request, Closure $next): Response
    {
        Context::add('trace_id', (string) Str::uuid());

        return $next($request);
    }
}
