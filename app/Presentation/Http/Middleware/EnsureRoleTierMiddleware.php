<?php

declare(strict_types=1);

namespace App\Presentation\Http\Middleware;

use App\Domain\Access\Enums\RoleTier;
use App\Domain\Access\ValueObjects\AuthenticatedUser;
use App\Presentation\Http\Api\Responses\ApiErrorResponseDTO;
use App\Presentation\Http\Api\Responses\ApiErrorTypeEnum;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use TypeError;
use ValueError;

/**
 * ============================================================
 * CRITICAL SECURITY MIDDLEWARE - DO NOT REMOVE OR BYPASS
 * ============================================================
 *
 * Allows a request only when the caller's Role belongs to the route's Role tier (ADR-0014).
 *
 * MUST run AFTER EnsureUserApprovedMiddleware, declared later in the same middleware
 * array: it is not on the priority list, so Laravel runs it in declared order.
 *
 * Usage: EnsureRoleTierMiddleware::using(RoleTier::Staff)
 * ============================================================
 */
final class EnsureRoleTierMiddleware
{
    public static function using(RoleTier $tier): string
    {
        return self::class . ':' . $tier->value;
    }

    /**
     * @param array<string, string> $extra
     */
    public static function logRejection(Request $request, ?AuthenticatedUser $user, array $extra = []): void
    {
        $userContext = $user === null ? [] : [
            'user_id' => $user->id,
            'email' => $user->email,
            'role' => $user->roleName,
        ];

        Log::channel('security')->warning('User role outside the route role tier', [
            'event' => 'api.auth.insufficient_role',
            ...$userContext,
            'path' => $request->path(),
            'ip' => $request->ip(),
            ...$extra,
        ]);
    }

    /**
     * @param Closure(Request): Response $next
     *
     * @throws ValueError When the route's tier parameter names no RoleTier
     * @throws TypeError When the route's tier parameter names no RoleTier
     */
    public function handle(Request $request, Closure $next, string $tier): Response
    {
        // intentional: from(), not tryFrom() — an unknown tier name throws rather than allowing the request.
        $roleTier = RoleTier::from($tier);

        /** @var AuthenticatedUser|null $authenticatedUser */
        $authenticatedUser = $request->attributes->get('authenticated_user');

        if ($authenticatedUser === null) {
            return $this->rejectUnauthenticated($request);
        }

        if (!$roleTier->includes($authenticatedUser->roleName)) {
            return $this->rejectInsufficientRole($request, $authenticatedUser, $roleTier);
        }

        return $next($request);
    }

    private function rejectInsufficientRole(Request $request, AuthenticatedUser $user, RoleTier $tier): Response
    {
        self::logRejection($request, $user, ['tier' => $tier->value]);

        return (new ApiErrorResponseDTO(
            type: ApiErrorTypeEnum::Forbidden,
            message: 'Insufficient role.',
            status: Response::HTTP_FORBIDDEN,
        ))->toJsonResponse();
    }

    private function rejectUnauthenticated(Request $request): Response
    {
        Log::channel('security')->error('EnsureRoleTierMiddleware called without authenticated_user', [
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
}
