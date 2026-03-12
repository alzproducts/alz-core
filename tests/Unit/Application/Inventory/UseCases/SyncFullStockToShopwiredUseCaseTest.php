<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Inventory\UseCases;

use App\Application\Contracts\Inventory\ProductStockRepositoryInterface;
use App\Application\Contracts\Linnworks\StockDashboardsClientInterface;
use App\Application\Contracts\LockManagerInterface;
use App\Application\Contracts\Shopwired\StockClientInterface;
use App\Application\Inventory\UseCases\SyncFullStockToShopwiredUseCase;
use App\Application\Results\StockUpdateResult;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Inventory\ValueObjects\ItemStockLevel;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Tests for SyncFullStockToShopwiredUseCase branching logic.
 *
 * Per TestingStrategy.md: Test orchestration decisions, business workflow
 * branches, and error handling paths. Skip pure delegation.
 */
#[CoversClass(SyncFullStockToShopwiredUseCase::class)]
final class SyncFullStockToShopwiredUseCaseTest extends TestCase
{
    private StockDashboardsClientInterface&MockInterface $linnworksClient;

    private ProductStockRepositoryInterface&MockInterface $stockRepository;

    private StockClientInterface&MockInterface $shopwiredClient;

    private LockManagerInterface&MockInterface $lockManager;

    private LoggerInterface&MockInterface $logger;

    private SyncFullStockToShopwiredUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->linnworksClient = Mockery::mock(StockDashboardsClientInterface::class);
        $this->stockRepository = Mockery::mock(ProductStockRepositoryInterface::class);
        $this->shopwiredClient = Mockery::mock(StockClientInterface::class);
        $this->lockManager = Mockery::mock(LockManagerInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

        // Lock manager executes callback immediately (pass-through for unit tests)
        $this->lockManager->shouldReceive('withLock')
            ->andReturnUsing(static fn(string $name, int $timeout, callable $callback) => $callback());

        $this->useCase = new SyncFullStockToShopwiredUseCase(
            $this->linnworksClient,
            $this->stockRepository,
            $this->shopwiredClient,
            $this->lockManager,
            $this->logger,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Happy Path Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_pushes_differences_between_linnworks_and_local_stock(): void
    {
        $this->linnworksClient->shouldReceive('getAllStockLevels')
            ->once()
            ->andReturn([
                new ItemStockLevel(Sku::fromTrusted('SKU-A'), 10),
                new ItemStockLevel(Sku::fromTrusted('SKU-B'), 20),
            ]);

        $this->stockRepository->shouldReceive('getAllStockLevels')
            ->once()
            ->andReturn([
                new ItemStockLevel(Sku::fromTrusted('SKU-A'), 5),  // different → push
                new ItemStockLevel(Sku::fromTrusted('SKU-B'), 20), // same → skip
            ]);

        $pushed = [new ItemStockLevel(Sku::fromTrusted('SKU-A'), 10)];

        $this->shopwiredClient->shouldReceive('updateStockQuantity')
            ->once()
            ->withArgs(static fn(array $items): bool => \count($items) === 1 && $items[0]->sku->value === 'SKU-A' && $items[0]->quantity === 10)
            ->andReturn(new StockUpdateResult(pushed: $pushed));

        $this->stockRepository->shouldReceive('updateStockLevels')
            ->once()
            ->with($pushed);

        $this->useCase->execute();
    }

    /*
    |--------------------------------------------------------------------------
    | No Differences Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_logs_and_returns_when_no_differences_found(): void
    {
        $this->linnworksClient->shouldReceive('getAllStockLevels')
            ->andReturn([
                new ItemStockLevel(Sku::fromTrusted('SKU-A'), 10),
            ]);

        $this->stockRepository->shouldReceive('getAllStockLevels')
            ->andReturn([
                new ItemStockLevel(Sku::fromTrusted('SKU-A'), 10), // identical
            ]);

        $this->shopwiredClient->shouldNotReceive('updateStockQuantity');
        $this->stockRepository->shouldNotReceive('updateStockLevels');

        $this->logger->shouldReceive('info')
            ->withArgs(static fn(string $msg) => \str_contains($msg, 'no differences'))
            ->once();

        $this->useCase->execute();
    }

    /*
    |--------------------------------------------------------------------------
    | Difference Filtering Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_skips_linnworks_skus_not_in_local_db(): void
    {
        $this->linnworksClient->shouldReceive('getAllStockLevels')
            ->andReturn([
                new ItemStockLevel(Sku::fromTrusted('LINNWORKS-ONLY'), 10),
            ]);

        // Local DB has no matching SKU
        $this->stockRepository->shouldReceive('getAllStockLevels')
            ->andReturn([]);

        $this->shopwiredClient->shouldNotReceive('updateStockQuantity');

        $this->useCase->execute();
    }

    #[Test]
    public function it_handles_multiple_differences_in_large_catalogue(): void
    {
        $linnworksStock = [];
        $localStock = [];

        // 50 items: 25 with differences, 25 identical
        for ($i = 0; $i < 50; $i++) {
            $sku = Sku::fromTrusted("SKU-{$i}");
            $linnworksStock[] = new ItemStockLevel($sku, $i * 2);
            $localStock[] = new ItemStockLevel($sku, $i < 25 ? 0 : $i * 2); // first 25 differ
        }

        $this->linnworksClient->shouldReceive('getAllStockLevels')
            ->andReturn($linnworksStock);

        $this->stockRepository->shouldReceive('getAllStockLevels')
            ->andReturn($localStock);

        $this->shopwiredClient->shouldReceive('updateStockQuantity')
            ->once()
            ->withArgs(static function (array $items): bool {
                // SKU-0 has quantity 0 in both (0*2 == 0) so only 24 diffs
                return \count($items) === 24;
            })
            ->andReturnUsing(static fn(array $items) => new StockUpdateResult(pushed: $items));

        $this->stockRepository->shouldReceive('updateStockLevels')->once();

        $this->useCase->execute();
    }

    /*
    |--------------------------------------------------------------------------
    | Partial Failure Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_updates_local_db_for_pushed_items_before_rethrowing_transport_failure(): void
    {
        $this->linnworksClient->shouldReceive('getAllStockLevels')
            ->andReturn([
                new ItemStockLevel(Sku::fromTrusted('SKU-A'), 10),
                new ItemStockLevel(Sku::fromTrusted('SKU-B'), 20),
            ]);

        $this->stockRepository->shouldReceive('getAllStockLevels')
            ->andReturn([
                new ItemStockLevel(Sku::fromTrusted('SKU-A'), 5),
                new ItemStockLevel(Sku::fromTrusted('SKU-B'), 15),
            ]);

        // Partial failure: SKU-A pushed, SKU-B's batch failed
        $pushed = [new ItemStockLevel(Sku::fromTrusted('SKU-A'), 10)];
        $transportFailure = new ExternalServiceUnavailableException('Shopwired');

        $this->shopwiredClient->shouldReceive('updateStockQuantity')
            ->once()
            ->andReturn(new StockUpdateResult(pushed: $pushed, transportFailure: $transportFailure));

        // Local DB should be updated for SKU-A (the pushed item)
        $this->stockRepository->shouldReceive('updateStockLevels')
            ->once()
            ->with($pushed);

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->useCase->execute();
    }

    #[Test]
    public function it_does_not_update_local_db_when_no_items_pushed(): void
    {
        $this->linnworksClient->shouldReceive('getAllStockLevels')
            ->andReturn([
                new ItemStockLevel(Sku::fromTrusted('SKU-A'), 10),
            ]);

        $this->stockRepository->shouldReceive('getAllStockLevels')
            ->andReturn([
                new ItemStockLevel(Sku::fromTrusted('SKU-A'), 5),
            ]);

        // All batches failed — nothing pushed
        $transportFailure = new ExternalServiceUnavailableException('Shopwired');

        $this->shopwiredClient->shouldReceive('updateStockQuantity')
            ->once()
            ->andReturn(new StockUpdateResult(pushed: [], transportFailure: $transportFailure));

        // Local DB should NOT be updated
        $this->stockRepository->shouldNotReceive('updateStockLevels');

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->useCase->execute();
    }
}
