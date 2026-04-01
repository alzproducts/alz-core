<?php

declare(strict_types=1);

namespace App\Presentation\Http\Middleware;

use App\Domain\Access\ValueObjects\AuthenticatedUser;
use App\Presentation\Http\Api\Responses\ApiErrorResponseDTO;
use App\Presentation\Http\Api\Responses\ApiErrorTypeEnum;
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

        if ($authenticatedUser === null) {
            return $this->rejectUnauthenticated($request);
        }

        if (!$authenticatedUser->hasBasicAuthorization()) {
            return $this->rejectUnapproved($request, $authenticatedUser);
        }

        return $next($request);
    }

    private function rejectUnauthenticated(Request $request): Response
    {
        Log::channel('security')->error('EnsureUserApprovedMiddleware called without authenticated_user', [
            'event' => 'api.auth.middleware_order_error',
            'path' => $request->path(),
            'ip' => $request->ip(),
        ]);

        return (new ApiErrorResponseDTO(
            type: ApiErrorTypeEnum::Unauthorized,
            message: 'Unauthorized.',
            status: Response::HTTP_UNAUTHORIZED,
        ))->toJsonResponse();
    }

    private function rejectUnapproved(Request $request, AuthenticatedUser $user): Response
    {
        Log::channel('security')->warning('Unapproved user attempted access', [
            'event' => 'api.auth.unapproved_user',
            'user_id' => $user->id,
            'email' => $user->email,
            'path' => $request->path(),
            'ip' => $request->ip(),
        ]);

        return (new ApiErrorResponseDTO(
            type: ApiErrorTypeEnum::Forbidden,
            message: 'Account pending approval.',
            status: Response::HTTP_FORBIDDEN,
        ))->toJsonResponse();
    }
}
