<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Access\ValueObjects\AuthenticatedUser;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Http\Request;
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
        // Bind AuthenticatedUser to resolve from current request's attributes
        // NOT a singleton - must resolve fresh for each request (Octane-safe)
        $this->app->bind(
            AuthenticatedUser::class,
            static function (Application $app): AuthenticatedUser {
                $request = $app->make(Request::class);
                $user = $request->attributes->get('authenticated_user');

                if (! $user instanceof AuthenticatedUser) {
                    // LogicException: programming error (route missing middleware)
                    // In PHPStan's unchecked list, avoids checkedExceptionInCallable
                    throw new LogicException(
                        'AuthenticatedUser not available. Ensure route has auth.supabase middleware.',
                    );
                }

                return $user;
            },
        );
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
