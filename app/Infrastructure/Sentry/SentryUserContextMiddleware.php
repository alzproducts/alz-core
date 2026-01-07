<?php

declare(strict_types=1);

namespace App\Infrastructure\Sentry;

use App\Domain\Access\ValueObjects\AuthenticatedUser;
use Closure;
use Illuminate\Http\Request;

use function Sentry\configureScope;

use Sentry\State\Scope;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attach authenticated user context to Sentry.
 *
 * Reads AuthenticatedUser from request attributes set by ValidateSupabaseJwtMiddleware.
 * MUST run AFTER ValidateSupabaseJwtMiddleware in the middleware chain.
 */
final class SentryUserContextMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authenticatedUser = $request->attributes->get('authenticated_user');

        if ($authenticatedUser instanceof AuthenticatedUser) {
            configureScope(static function (Scope $scope) use ($authenticatedUser): void {
                $scope->setUser([
                    'id' => $authenticatedUser->id,
                    'email' => $authenticatedUser->email,
                ]);
            });
        }

        return $next($request);
    }
}
