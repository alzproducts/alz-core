<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Api\Controllers;

use App\Application\Catalog\UseCases\RefreshAllProductsUseCase;
use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Application\Contracts\Linnworks\InventoryUpdateClientInterface;
use App\Application\Contracts\Linnworks\LinnworksSyncDispatcherInterface;
use App\Application\Contracts\Shopwired\PriceUpdateClientInterface;
use App\Application\Contracts\Shopwired\ProductClientInterface;
use App\Application\Contracts\Shopwired\ProductFieldUpdateClientInterface;
use App\Application\Contracts\Shopwired\ProductUpdateClientInterface;
use App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface;
use App\Presentation\Http\Api\Controllers\ProductUpdateController;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\AuthenticatesAsApprovedUser;
use Tests\TestCase;

#[CoversClass(ProductUpdateController::class)]
final class ProductUpdateControllerRefreshAllTest extends TestCase
{
    use AuthenticatesAsApprovedUser;

    private ShopwiredSyncDispatcherInterface&MockInterface $shopwiredDispatcher;

    private LinnworksSyncDispatcherInterface&MockInterface $linnworksDispatcher;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->shopwiredDispatcher = Mockery::mock(ShopwiredSyncDispatcherInterface::class);
        $this->linnworksDispatcher = Mockery::mock(LinnworksSyncDispatcherInterface::class);

        $this->app->instance(ShopwiredSyncDispatcherInterface::class, $this->shopwiredDispatcher);
        $this->app->instance(LinnworksSyncDispatcherInterface::class, $this->linnworksDispatcher);

        // ProductUpdateController's constructor eagerly resolves every use case it
        // holds — including the update/price/cost-price/refresh paths this test
        // doesn't exercise. Those bindings call Shopwired/Linnworks factory
        // getTransport() methods that throw on empty config. Bind empty mocks so
        // the container can satisfy type constraints without triggering real
        // config reads.
        $this->app->instance(ProductClientInterface::class, Mockery::mock(ProductClientInterface::class));
        $this->app->instance(ProductUpdateClientInterface::class, Mockery::mock(ProductUpdateClientInterface::class));
        $this->app->instance(ProductFieldUpdateClientInterface::class, Mockery::mock(ProductFieldUpdateClientInterface::class));
        $this->app->instance(PriceUpdateClientInterface::class, Mockery::mock(PriceUpdateClientInterface::class));
        $this->app->instance(InventoryClientInterface::class, Mockery::mock(InventoryClientInterface::class));
        $this->app->instance(InventoryUpdateClientInterface::class, Mockery::mock(InventoryUpdateClientInterface::class));
    }

    #[Override]
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function unauthenticated_refresh_all_returns_401(): void
    {
        $response = $this->postJson('/api/products/refresh');

        $response->assertStatus(401);
    }

    #[Test]
    public function refresh_all_dispatches_both_jobs_and_returns_202_with_body(): void
    {
        $this->shopwiredDispatcher
            ->shouldReceive('dispatchAllProductsSync')
            ->once();

        $this->linnworksDispatcher
            ->shouldReceive('dispatchFullStockItemsSync')
            ->once();

        $response = $this->asApprovedUser()->postJson('/api/products/refresh');

        $response->assertStatus(202);
        $response->assertExactJson([
            'message' => 'Product & stock refresh queued',
            'estimated_duration_seconds' => RefreshAllProductsUseCase::ESTIMATED_DURATION_SECONDS,
        ]);
    }

}
