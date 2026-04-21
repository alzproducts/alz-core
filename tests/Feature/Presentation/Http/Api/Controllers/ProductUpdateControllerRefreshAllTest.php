<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Api\Controllers;

use App\Application\Catalog\UseCases\RefreshAllProductsUseCase;
use App\Application\Contracts\Linnworks\LinnworksSyncDispatcherInterface;
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
