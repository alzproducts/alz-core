<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Access\ValueObjects\AuthenticatedUser;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Override;

/**
 * Provides AuthenticatedUser resolution from request attributes.
 *
 * Bridges the middleware layer (which sets authenticated_user in request
 * attributes) with the controller layer (which uses DI for type-safe access).
 *
 * IMPORTANT: Only use in controllers behind auth.supabase middleware.
 * The binding throws if no authenticated user is available.
 */
final class AuthServiceProvider extends ServiceProvider implements DeferrableProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->bind(
            AuthenticatedUser::class,
            static fn(Application $app): AuthenticatedUser => self::resolveFromRequest($app),
        );
    }

    private static function resolveFromRequest(Application $app): AuthenticatedUser
    {
        $request = $app->make(Request::class);
        $user = $request->attributes->get('authenticated_user');

        if (! $user instanceof AuthenticatedUser) {
            Log::warning('AuthenticatedUser not found in request attributes', [
                'path' => $request->path(),
                'hint' => 'Route may be missing auth.supabase middleware',
            ]);

            throw new LogicException(
                'AuthenticatedUser not available. Ensure route has auth.supabase middleware.',
            );
        }

        return $user;
    }

    /**
     * @return list<class-string>
     */
    #[Override]
    public function provides(): array
    {
        return [AuthenticatedUser::class];
    }
}
