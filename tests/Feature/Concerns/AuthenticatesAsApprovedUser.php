<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Domain\Access\ValueObjects\AuthenticatedUser;
use App\Presentation\Http\Auth\Middleware\ValidateSupabaseJwtMiddleware;
use App\Presentation\Http\Middleware\EnsureUserApprovedMiddleware;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

trait AuthenticatesAsApprovedUser
{
    protected function asApprovedUser(): static
    {
        $approvedUser = new AuthenticatedUser(
            id: 'd9dd22a9-c3ab-413b-8a93-25b462231a98',
            email: 'test@example.com',
            isApproved: true,
            roleName: 'admin',
        );

        $stub = new class ($approvedUser) {
            public function __construct(private readonly AuthenticatedUser $user) {}

            public function handle(Request $request, Closure $next): Response
            {
                $request->attributes->set('authenticated_user', $this->user);

                return $next($request);
            }
        };

        $this->app->bind(ValidateSupabaseJwtMiddleware::class, static fn() => $stub);
        $this->app->bind(EnsureUserApprovedMiddleware::class, static fn() => new class {
            public function handle(Request $request, Closure $next): Response
            {
                return $next($request);
            }
        });

        return $this;
    }
}
