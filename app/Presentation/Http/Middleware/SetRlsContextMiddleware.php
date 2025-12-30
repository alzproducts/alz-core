<?php

declare(strict_types=1);

namespace App\Presentation\Http\Middleware;

use App\Domain\Access\ValueObjects\AuthenticatedUser;
use App\Infrastructure\Persistence\Database\RlsPostgresConnection;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets RLS (Row-Level Security) context for database queries.
 *
 * Extracts the authenticated user ID from the request and stores it in
 * Laravel's Context facade. The `pgsql_rls` database connection reads
 * this value to set PostgreSQL session variables for RLS enforcement.
 *
 * Must run AFTER authentication middleware (ValidateSupabaseJwtMiddleware)
 * which sets the `authenticated_user` request attribute.
 *
 * @see RlsPostgresConnection
 */
final class SetRlsContextMiddleware
{
    /**
     * Set RLS user context from authenticated user.
     *
     * @param Closure(Request): Response $next
     *
     * @throws RuntimeException If downstream code uses pgsql_rls without user context
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authenticatedUser = $request->attributes->get('authenticated_user');

        if ($authenticatedUser instanceof AuthenticatedUser) {
            Context::add('rls_user_id', $authenticatedUser->id);
        }

        return $next($request);
    }
}
