<?php

declare(strict_types=1);

namespace App\Presentation\Http\Middleware;

use App\Domain\Access\ValueObjects\AuthenticatedUser;
use App\Providers\RlsDatabaseServiceProvider;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets RLS (Row-Level Security) context for database queries.
 *
 * Extracts the authenticated user ID from the request and:
 * 1. Stores it in Laravel's Context facade (for guard verification)
 * 2. Sets PostgreSQL session variable `request.jwt.claims` (for RLS policies)
 *
 * IMPORTANT: Always sets the PostgreSQL session variable to prevent Octane
 * connection reuse from leaking claims between requests. If authenticated,
 * sets user claims. If not authenticated, sets empty claims to clear stale values.
 *
 * The `beforeExecuting` callback in RlsDatabaseServiceProvider acts as a guard
 * to ensure context was set before allowing queries on pgsql_rls connection.
 *
 * Must run AFTER authentication middleware (ValidateSupabaseJwtMiddleware)
 * which sets the `authenticated_user` request attribute.
 *
 * @see RlsDatabaseServiceProvider
 */
final class SetRlsContextMiddleware
{
    /**
     * Set RLS user context from authenticated user.
     *
     * @param Closure(Request): Response $next
     *
     * @throws JsonException If JWT claims cannot be encoded
     * @throws RuntimeException If database statement fails
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authenticatedUser = $request->attributes->get('authenticated_user');

        if ($authenticatedUser instanceof AuthenticatedUser) {
            // Set Laravel Context for guard verification
            Context::add('rls_user_id', $authenticatedUser->id);

            // Set PostgreSQL session variable for RLS policies
            // The 'sub' claim matches what Supabase RLS policies expect
            $claims = \json_encode(['sub' => $authenticatedUser->id], JSON_THROW_ON_ERROR);
        } else {
            // Clear stale claims from previous Octane request (defense in depth)
            // This prevents unauthenticated requests from seeing previous user's data
            $claims = '{}';
        }

        // Always set claims to ensure clean state for this request
        DB::connection('pgsql_rls')->statement(
            "SELECT set_config('request.jwt.claims', ?, false)",
            [$claims],
        );

        return $next($request);
    }
}
