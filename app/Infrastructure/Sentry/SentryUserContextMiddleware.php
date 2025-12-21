<?php

declare(strict_types=1);

namespace App\Infrastructure\Sentry;

use Closure;
use Illuminate\Http\Request;

use function Sentry\configureScope;

use Sentry\State\Scope;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attach authenticated user context to Sentry.
 *
 * Reads auth_user_id/auth_user_email attached by ValidateSupabaseJwtMiddleware.
 * MUST run AFTER ValidateSupabaseJwtMiddleware in the middleware chain.
 */
final class SentryUserContextMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->input('auth_user_id');
        $userEmail = $request->input('auth_user_email');

        if ($userId !== null) {
            configureScope(static function (Scope $scope) use ($userId, $userEmail): void {
                $scope->setUser([
                    'id' => $userId,
                    'email' => $userEmail,
                ]);
            });
        }

        return $next($request);
    }
}
