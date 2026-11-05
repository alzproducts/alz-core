<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Routing;

use App\Domain\Access\Enums\RoleTier;
use App\Presentation\Http\Middleware\EnsureRoleTierMiddleware;
use App\Presentation\Http\Middleware\EnsureUserApprovedMiddleware;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every approval-gated route must also carry the Staff role tier, resolved the way the
 * router runs it: groups expanded and withoutMiddleware exclusions applied.
 */
#[CoversNothing]
final class ApprovalRoutesRequireRoleTierTest extends TestCase
{
    private const array ADMIN_AREA_ROUTES = [
        'PUT api/catalog/custom-field-definitions/{definitionUuid}/general-settings',
        'PUT api/catalog/custom-field-definitions/{definitionUuid}/product-settings',
    ];

    #[Test]
    public function every_approval_route_carries_the_staff_tier_after_the_approval_middleware(): void
    {
        $approvalRoutes = $this->approvalRoutes();

        self::assertNotEmpty($approvalRoutes);

        foreach ($approvalRoutes as $key => $middleware) {
            $roleEntries = \array_values(\array_filter(
                $middleware,
                static fn(string $entry): bool => \str_starts_with($entry, EnsureRoleTierMiddleware::class . ':'),
            ));

            $expected = \in_array($key, self::ADMIN_AREA_ROUTES, true)
                ? [EnsureRoleTierMiddleware::using(RoleTier::Staff), EnsureRoleTierMiddleware::using(RoleTier::AdminArea)]
                : [EnsureRoleTierMiddleware::using(RoleTier::Staff)];

            self::assertSame($expected, $roleEntries, "Route [{$key}] has the wrong role middleware.");

            $approvalIndex = \array_search(EnsureUserApprovedMiddleware::class, $middleware, true);
            foreach ($roleEntries as $roleEntry) {
                self::assertGreaterThan(
                    $approvalIndex,
                    \array_search($roleEntry, $middleware, true),
                    "Route [{$key}] runs {$roleEntry} before the approval middleware.",
                );
            }
        }
    }

    #[Test]
    public function both_admin_area_routes_are_approval_routes(): void
    {
        $approvalRoutes = $this->approvalRoutes();

        foreach (self::ADMIN_AREA_ROUTES as $key) {
            self::assertArrayHasKey($key, $approvalRoutes);
        }
    }

    /**
     * @return array<string, list<string>> route key ("METHOD uri") => resolved string middleware
     */
    private function approvalRoutes(): array
    {
        $router = $this->app->make(Router::class);
        $approvalRoutes = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            $middleware = \array_values(\array_filter(
                $router->gatherRouteMiddleware($route),
                static fn(mixed $entry): bool => \is_string($entry),
            ));

            if (!\in_array(EnsureUserApprovedMiddleware::class, $middleware, true)) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $approvalRoutes["{$method} {$route->uri()}"] = $middleware;
            }
        }

        return $approvalRoutes;
    }
}
