<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\UseCases;

use App\Application\Catalog\UseCases\RefreshAllProductsUseCase;
use App\Application\Contracts\Linnworks\LinnworksSyncDispatcherInterface;
use App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(RefreshAllProductsUseCase::class)]
final class RefreshAllProductsUseCaseTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private ShopwiredSyncDispatcherInterface&MockInterface $shopwiredDispatcher;

    private LinnworksSyncDispatcherInterface&MockInterface $linnworksDispatcher;

    private LoggerInterface&MockInterface $logger;

    private RefreshAllProductsUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->shopwiredDispatcher = Mockery::mock(ShopwiredSyncDispatcherInterface::class);
        $this->linnworksDispatcher = Mockery::mock(LinnworksSyncDispatcherInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('info')->byDefault();

        $this->useCase = new RefreshAllProductsUseCase(
            $this->shopwiredDispatcher,
            $this->linnworksDispatcher,
            $this->logger,
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function dispatches_both_full_sync_jobs(): void
    {
        $this->shopwiredDispatcher
            ->shouldReceive('dispatchAllProductsSync')
            ->once();

        $this->linnworksDispatcher
            ->shouldReceive('dispatchFullStockItemsSync')
            ->once();

        $this->useCase->execute();
    }

    #[Test]
    public function logs_start_and_completion(): void
    {
        $this->shopwiredDispatcher->shouldReceive('dispatchAllProductsSync');
        $this->linnworksDispatcher->shouldReceive('dispatchFullStockItemsSync');

        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('info')
            ->once()
            ->with('Dispatching full product + stock catalogue refresh');
        $this->logger->shouldReceive('info')
            ->once()
            ->with('Full catalogue refresh dispatch complete');

        $useCase = new RefreshAllProductsUseCase(
            $this->shopwiredDispatcher,
            $this->linnworksDispatcher,
            $this->logger,
        );

        $useCase->execute();
    }
}
