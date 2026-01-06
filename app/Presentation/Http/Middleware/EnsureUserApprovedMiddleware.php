<?php

declare(strict_types=1);

namespace App\Presentation\Http\Middleware;

use App\Domain\Access\ValueObjects\AuthenticatedUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * ============================================================
 * CRITICAL SECURITY MIDDLEWARE - DO NOT REMOVE OR BYPASS
 * ============================================================
 *
 * Enforces user approval status. All users must be explicitly
 * approved by an admin before accessing protected API endpoints.
 *
 * MUST run AFTER ValidateSupabaseJwtMiddleware (requires authenticated_user).
 *
 * Usage: Apply middleware group 'auth.supabase' to routes.
 * ============================================================
 */
final class EnsureUserApprovedMiddleware
{
    /**
     * Verify the authenticated user has been approved by an admin.
     *
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var AuthenticatedUser|null $authenticatedUser */
        $authenticatedUser = $request->attributes->get('authenticated_user');

        // This should never happen if middleware ordering is correct
        if ($authenticatedUser === null) {
            Log::channel('security')->error('EnsureUserApprovedMiddleware called without authenticated_user', [
                'event' => 'api.auth.middleware_order_error',
                'path' => $request->path(),
                'ip' => $request->ip(),
            ]);

            return \response()->json(['error' => 'Unauthorized'], 401);
        }

        if (!$authenticatedUser->hasBasicAuthorization()) {
            Log::channel('security')->warning('Unapproved user attempted access', [
                'event' => 'api.auth.unapproved_user',
                'user_id' => $authenticatedUser->id,
                'email' => $authenticatedUser->email,
                'path' => $request->path(),
                'ip' => $request->ip(),
            ]);

            return \response()->json([
                'error' => 'Account pending approval',
                'code' => 'ACCOUNT_NOT_APPROVED',
            ], 403);
        }

        return $next($request);
    }
}
