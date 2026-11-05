<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Middleware;

use App\Application\Linnworks\UpdateCostPriceBySupplier\UpdateCostPriceBySupplierUseCase;
use App\Application\Shopwired\PricingUpdate\Results\PriceUpdateResult;
use App\Application\Shopwired\PricingUpdate\UseCases\UpdateProductPricesUseCase;
use App\Domain\Access\Enums\RoleTier;
use App\Presentation\Http\Auth\Middleware\ValidateSupabaseJwtMiddleware;
use App\Presentation\Http\Middleware\EnsureRoleTierMiddleware;
use App\Presentation\Http\Middleware\EnsureUserApprovedMiddleware;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\SendsRoleTokens;
use Tests\TestCase;

#[CoversClass(EnsureRoleTierMiddleware::class)]
final class EnsureRoleTierMiddlewareTest extends TestCase
{
    use SendsRoleTokens;

    private const string PRICES_ROUTE = '/api/products/123/prices';

    private const array PRICES_BODY = [
        'sku_updates' => [['sku' => 'ROLE-TEST-1', 'price' => 10.0]],
        'sale_settings' => null,
    ];

    private UpdateProductPricesUseCase&MockInterface $pricesUseCase;

    private MockInterface $securityLogger;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Log::clearResolvedInstances();
        Log::spy();
        $this->securityLogger = Mockery::spy();
        Log::shouldReceive('channel')->andReturnUsing(
            fn(?string $channel = null): MockInterface => $channel === 'security' ? $this->securityLogger : Mockery::spy(),
        );

        $this->pricesUseCase = Mockery::mock(UpdateProductPricesUseCase::class);
        $this->app->instance(UpdateProductPricesUseCase::class, $this->pricesUseCase);
        $this->app->instance(UpdateCostPriceBySupplierUseCase::class, Mockery::mock(UpdateCostPriceBySupplierUseCase::class));

        $this->fakeSupabaseJwks();
    }

    #[Override]
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    #[Test]
    public function guest_is_rejected_with_403_before_the_use_case_runs(): void
    {
        $this->pricesUseCase->shouldNotReceive('execute');

        $this->asRole('guest')->putJson(self::PRICES_ROUTE, self::PRICES_BODY)
            ->assertForbidden()
            ->assertExactJson(['error' => ['type' => 'forbidden', 'message' => 'Insufficient role.']]);
    }

    #[Test]
    public function standard_role_reaches_the_endpoint_and_gets_its_200_response(): void
    {
        $this->pricesUseCase->shouldReceive('execute')
            ->once()
            ->andReturn(new PriceUpdateResult(total: 1, succeeded: 1));

        $this->asRole('standard')->putJson(self::PRICES_ROUTE, self::PRICES_BODY)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('succeeded', 1);

        $this->securityLogger->shouldNotHaveReceived('warning');
    }

    #[Test]
    #[DataProvider('unrecognisedRoleProvider')]
    public function an_absent_null_or_unrecognised_role_is_rejected_with_403(?string $roleName, bool $omitRoleName): void
    {
        $this->pricesUseCase->shouldNotReceive('execute');

        $this->asRole($roleName, $omitRoleName)->putJson(self::PRICES_ROUTE, self::PRICES_BODY)
            ->assertForbidden()
            ->assertExactJson(['error' => ['type' => 'forbidden', 'message' => 'Insufficient role.']]);
    }

    /**
     * @return array<string, array{string|null, bool}>
     */
    public static function unrecognisedRoleProvider(): array
    {
        return [
            'role_name absent' => [null, true],
            'role_name null' => [null, false],
            'role_name empty' => ['', false],
            'tier name staff' => ['staff', false],
        ];
    }

    #[Test]
    public function a_role_rejection_logs_an_insufficient_role_security_event(): void
    {
        $this->pricesUseCase->shouldNotReceive('execute');

        $this->asRole('guest')->putJson(self::PRICES_ROUTE, self::PRICES_BODY)->assertForbidden();

        $this->securityLogger->shouldHaveReceived('warning')->once()->with(
            'User role outside the route role tier',
            Mockery::on(static fn(array $context): bool => $context['event'] === 'api.auth.insufficient_role'
                && $context['user_id'] === 'd9dd22a9-c3ab-413b-8a93-25b462231a98'
                && $context['email'] === 'test@example.com'
                && $context['role'] === 'guest'
                && $context['path'] === 'api/products/123/prices'
                && $context['ip'] === '127.0.0.1'),
        );
    }

    #[Test]
    public function a_request_without_an_authenticated_user_gets_401_and_logs_a_middleware_order_error(): void
    {
        $reached = false;
        Route::get('/api/_test/role-tier-without-jwt', static function () use (&$reached) {
            $reached = true;

            return \response()->json(['reached' => true]);
        })->middleware(EnsureRoleTierMiddleware::using(RoleTier::Staff));

        $this->getJson('/api/_test/role-tier-without-jwt')
            ->assertUnauthorized()
            ->assertExactJson(['error' => ['type' => 'unauthorized', 'message' => 'Unauthorized.']]);

        self::assertFalse($reached);
        $this->securityLogger->shouldHaveReceived('error')->once()->with(
            'EnsureRoleTierMiddleware called without authenticated_user',
            Mockery::on(static fn(array $context): bool => $context['event'] === 'api.auth.middleware_order_error'
                && $context['path'] === 'api/_test/role-tier-without-jwt'),
        );
    }

    #[Test]
    public function an_unknown_tier_parameter_errors_instead_of_reaching_the_controller(): void
    {
        $reached = false;
        Route::get('/api/_test/role-tier-bogus', static function () use (&$reached) {
            $reached = true;

            return \response()->json(['reached' => true]);
        })->middleware([
            ValidateSupabaseJwtMiddleware::class,
            EnsureUserApprovedMiddleware::class,
            EnsureRoleTierMiddleware::class . ':bogus',
        ]);

        $this->asRole('admin')->getJson('/api/_test/role-tier-bogus')
            ->assertServerError();

        self::assertFalse($reached);
    }
}
