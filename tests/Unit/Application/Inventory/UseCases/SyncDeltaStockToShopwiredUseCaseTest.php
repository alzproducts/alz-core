<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Inventory\UseCases;

use App\Application\Contracts\Inventory\ProductStockRepositoryInterface;
use App\Application\Contracts\Inventory\SyncCursorRepositoryInterface;
use App\Application\Contracts\Linnworks\StockDashboardsClientInterface;
use App\Application\Contracts\LockManagerInterface;
use App\Application\Contracts\Shopwired\StockClientInterface;
use App\Application\Enums\SyncCursorType;
use App\Application\Inventory\DTOs\StockLevelDeltaDTO;
use App\Application\Inventory\UseCases\SyncDeltaStockToShopwiredUseCase;
use App\Application\Results\StockUpdateResult;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Inventory\ValueObjects\ItemStockLevel;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Tests for SyncDeltaStockToShopwiredUseCase branching logic.
 *
 * Per TestingStrategy.md: Test orchestration decisions, business workflow
 * branches, and error handling paths. Skip pure delegation.
 */
#[CoversClass(SyncDeltaStockToShopwiredUseCase::class)]
final class SyncDeltaStockToShopwiredUseCaseTest extends TestCase
{
    private StockDashboardsClientInterface&MockInterface $linnworksClient;

    private ProductStockRepositoryInterface&MockInterface $stockRepository;

    private SyncCursorRepositoryInterface&MockInterface $cursorRepository;

    private StockClientInterface&MockInterface $shopwiredClient;

    private LockManagerInterface&MockInterface $lockManager;

    private LoggerInterface&MockInterface $logger;

    private SyncDeltaStockToShopwiredUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->linnworksClient = Mockery::mock(StockDashboardsClientInterface::class);
        $this->stockRepository = Mockery::mock(ProductStockRepositoryInterface::class);
        $this->cursorRepository = Mockery::mock(SyncCursorRepositoryInterface::class);
        $this->shopwiredClient = Mockery::mock(StockClientInterface::class);
        $this->lockManager = Mockery::mock(LockManagerInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

        // Lock manager executes callback immediately (pass-through for unit tests)
        $this->lockManager->shouldReceive('withLock')
            ->andReturnUsing(static fn(string $name, int $timeout, callable $callback) => $callback());

        $this->useCase = new SyncDeltaStockToShopwiredUseCase(
            $this->linnworksClient,
            $this->stockRepository,
            $this->cursorRepository,
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
    public function it_pushes_differences_and_advances_cursor(): void
    {
        $cursor = new DateTimeImmutable('2026-03-12 10:00:00');
        $deltaDate = new DateTimeImmutable('2026-03-12 10:05:00');

        $this->cursorRepository->shouldReceive('getLastSyncDate')
            ->once()
            ->with(SyncCursorType::LinnworksStockDelta)
            ->andReturn($cursor);

        $this->linnworksClient->shouldReceive('getStockLevelsSince')
            ->once()
            ->andReturn([
                new StockLevelDeltaDTO(Sku::fromTrusted('SKU-A'), 10, $deltaDate),
            ]);

        $this->stockRepository->shouldReceive('getStockLevelsBySkus')
            ->once()
            ->andReturn([
                new ItemStockLevel(Sku::fromTrusted('SKU-A'), 5), // different → should push
            ]);

        $pushed = [new ItemStockLevel(Sku::fromTrusted('SKU-A'), 10)];
        $this->shopwiredClient->shouldReceive('updateStockQuantity')
            ->once()
            ->andReturn(new StockUpdateResult(pushed: $pushed));

        $this->stockRepository->shouldReceive('updateStockLevels')
            ->once()
            ->with($pushed);

        $this->cursorRepository->shouldReceive('updateLastSyncDate')
            ->once()
            ->with(SyncCursorType::LinnworksStockDelta, $deltaDate);

        $this->useCase->execute();
    }

    /*
    |--------------------------------------------------------------------------
    | Early Return Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_early_when_no_delta_changes(): void
    {
        $this->cursorRepository->shouldReceive('getLastSyncDate')
            ->andReturn(new DateTimeImmutable('2026-03-12 10:00:00'));

        $this->linnworksClient->shouldReceive('getStockLevelsSince')
            ->once()
            ->andReturn([]);

        // Lock should never be acquired when there's nothing to sync
        $this->lockManager->shouldNotReceive('withLock');
        // Override the default setup mock for this specific test
        $this->lockManager = Mockery::mock(LockManagerInterface::class);

        $this->shopwiredClient->shouldNotReceive('updateStockQuantity');
        $this->cursorRepository->shouldNotReceive('updateLastSyncDate');

        // Recreate use case with the fresh lock manager
        $useCase = new SyncDeltaStockToShopwiredUseCase(
            $this->linnworksClient,
            $this->stockRepository,
            $this->cursorRepository,
            $this->shopwiredClient,
            $this->lockManager,
            $this->logger,
        );

        $useCase->execute();
    }

    #[Test]
    public function it_skips_push_when_no_differences_found(): void
    {
        $deltaDate = new DateTimeImmutable('2026-03-12 10:05:00');

        $this->cursorRepository->shouldReceive('getLastSyncDate')
            ->andReturn(new DateTimeImmutable('2026-03-12 10:00:00'));

        $this->linnworksClient->shouldReceive('getStockLevelsSince')
            ->andReturn([
                new StockLevelDeltaDTO(Sku::fromTrusted('SKU-A'), 10, $deltaDate),
            ]);

        $this->stockRepository->shouldReceive('getStockLevelsBySkus')
            ->andReturn([
                new ItemStockLevel(Sku::fromTrusted('SKU-A'), 10), // same → no diff
            ]);

        $this->shopwiredClient->shouldNotReceive('updateStockQuantity');
        $this->stockRepository->shouldNotReceive('updateStockLevels');

        // Cursor still advances even when no differences (delta was fetched)
        $this->cursorRepository->shouldReceive('updateLastSyncDate')
            ->once()
            ->with(SyncCursorType::LinnworksStockDelta, $deltaDate);

        $this->useCase->execute();
    }

    /*
    |--------------------------------------------------------------------------
    | Cursor Resolution Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_uses_default_lookback_when_no_cursor_exists(): void
    {
        $this->cursorRepository->shouldReceive('getLastSyncDate')
            ->andReturn(null);

        $this->linnworksClient->shouldReceive('getStockLevelsSince')
            ->once()
            ->withArgs(static function (DateTimeImmutable $since): bool {
                // Default lookback is 24 hours — verify it's roughly 24h ago
                $diff = (new DateTimeImmutable())->getTimestamp() - $since->getTimestamp();

                return $diff >= 86_300 && $diff <= 86_500; // 24h ± ~100s
            })
            ->andReturn([]);

        $this->useCase->execute();
    }

    #[Test]
    public function it_caps_stale_cursor_to_max_lookback(): void
    {
        // Cursor is 48 hours old — should be capped to 1 hour
        $staleCursor = new DateTimeImmutable('-48 hours');

        $this->cursorRepository->shouldReceive('getLastSyncDate')
            ->andReturn($staleCursor);

        $this->linnworksClient->shouldReceive('getStockLevelsSince')
            ->once()
            ->withArgs(static function (DateTimeImmutable $since): bool {
                // Max lookback is 1 hour — should be capped
                $diff = (new DateTimeImmutable())->getTimestamp() - $since->getTimestamp();

                return $diff >= 3_500 && $diff <= 3_700; // 1h ± ~100s
            })
            ->andReturn([]);

        // Verify stale cursor is logged at info level (expected during quiet periods)
        $this->logger->shouldReceive('info')
            ->once()
            ->withArgs(static fn(string $msg) => \str_contains($msg, 'stale'));

        $this->useCase->execute();
    }

    #[Test]
    public function it_uses_cursor_as_is_when_within_max_lookback(): void
    {
        $recentCursor = new DateTimeImmutable('-30 minutes');

        $this->cursorRepository->shouldReceive('getLastSyncDate')
            ->andReturn($recentCursor);

        $this->linnworksClient->shouldReceive('getStockLevelsSince')
            ->once()
            ->with($recentCursor) // Exact cursor passed through
            ->andReturn([]);

        $this->useCase->execute();
    }

    /*
    |--------------------------------------------------------------------------
    | Deduplication Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_deduplicates_by_sku_keeping_newest_entry(): void
    {
        $this->cursorRepository->shouldReceive('getLastSyncDate')
            ->andReturn(new DateTimeImmutable('2026-03-12 10:00:00'));

        $olderDate = new DateTimeImmutable('2026-03-12 10:01:00');
        $newerDate = new DateTimeImmutable('2026-03-12 10:02:00');

        $this->linnworksClient->shouldReceive('getStockLevelsSince')
            ->andReturn([
                new StockLevelDeltaDTO(Sku::fromTrusted('SKU-A'), 5, $olderDate),  // older
                new StockLevelDeltaDTO(Sku::fromTrusted('SKU-A'), 10, $newerDate), // newer — kept
            ]);

        $this->stockRepository->shouldReceive('getStockLevelsBySkus')
            ->once()
            ->withArgs(static function (array $skus): bool {
                // After dedup, only 1 SKU should be queried
                return \count($skus) === 1 && $skus[0]->value === 'SKU-A';
            })
            ->andReturn([
                new ItemStockLevel(Sku::fromTrusted('SKU-A'), 3), // different from 10
            ]);

        $this->shopwiredClient->shouldReceive('updateStockQuantity')
            ->once()
            ->withArgs(static function (array $items): bool {
                // Should push level 10 (from newer entry), not 5
                return \count($items) === 1 && $items[0]->quantity === 10;
            })
            ->andReturn(new StockUpdateResult(pushed: [new ItemStockLevel(Sku::fromTrusted('SKU-A'), 10)]));

        $this->stockRepository->shouldReceive('updateStockLevels')->once();

        // Cursor should advance to the newest date
        $this->cursorRepository->shouldReceive('updateLastSyncDate')
            ->once()
            ->with(SyncCursorType::LinnworksStockDelta, $newerDate);

        $this->useCase->execute();
    }

    /*
    |--------------------------------------------------------------------------
    | Difference Filtering Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_skips_delta_skus_not_in_local_db(): void
    {
        $deltaDate = new DateTimeImmutable('2026-03-12 10:05:00');

        $this->cursorRepository->shouldReceive('getLastSyncDate')
            ->andReturn(new DateTimeImmutable('2026-03-12 10:00:00'));

        $this->linnworksClient->shouldReceive('getStockLevelsSince')
            ->andReturn([
                new StockLevelDeltaDTO(Sku::fromTrusted('NEW-SKU'), 10, $deltaDate),
            ]);

        // Local DB doesn't have this SKU — it should be skipped
        $this->stockRepository->shouldReceive('getStockLevelsBySkus')
            ->andReturn([]);

        $this->shopwiredClient->shouldNotReceive('updateStockQuantity');
        $this->stockRepository->shouldNotReceive('updateStockLevels');

        // Cursor still advances
        $this->cursorRepository->shouldReceive('updateLastSyncDate')
            ->once();

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
        $deltaDate = new DateTimeImmutable('2026-03-12 10:05:00');

        $this->cursorRepository->shouldReceive('getLastSyncDate')
            ->andReturn(new DateTimeImmutable('2026-03-12 10:00:00'));

        $this->linnworksClient->shouldReceive('getStockLevelsSince')
            ->andReturn([
                new StockLevelDeltaDTO(Sku::fromTrusted('SKU-A'), 10, $deltaDate),
                new StockLevelDeltaDTO(Sku::fromTrusted('SKU-B'), 20, $deltaDate),
            ]);

        $this->stockRepository->shouldReceive('getStockLevelsBySkus')
            ->andReturn([
                new ItemStockLevel(Sku::fromTrusted('SKU-A'), 5),
                new ItemStockLevel(Sku::fromTrusted('SKU-B'), 15),
            ]);

        // Partial failure: SKU-A pushed, SKU-B's batch failed
        $pushed = [new ItemStockLevel(Sku::fromTrusted('SKU-A'), 10)];
        $transportFailure = new ExternalServiceUnavailableException('Shopwired');

        $this->shopwiredClient->shouldReceive('updateStockQuantity')
            ->once()
            ->andReturn(new StockUpdateResult(pushed: $pushed, transportFailures: [$transportFailure]));

        // Local DB should be updated for SKU-A (the pushed item)
        $this->stockRepository->shouldReceive('updateStockLevels')
            ->once()
            ->with($pushed);

        // Cursor should NOT advance (exception propagates before cursor update)
        $this->cursorRepository->shouldNotReceive('updateLastSyncDate');

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->useCase->execute();
    }
}
