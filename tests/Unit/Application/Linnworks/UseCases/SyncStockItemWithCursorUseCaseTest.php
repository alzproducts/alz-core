<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Linnworks\UseCases;

use App\Application\Contracts\Inventory\SyncCursorRepositoryInterface;
use App\Application\Contracts\Linnworks\StockDashboardsClientInterface;
use App\Application\Enums\SyncCursorType;
use App\Application\Linnworks\DTOs\ModifiedStockItemDTO;
use App\Application\Linnworks\UseCases\SyncStockItemWithCursorUseCase;
use App\Domain\ValueObjects\Guid;
use DateTimeImmutable;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * SyncStockItemWithCursorUseCase Unit Tests.
 *
 * Tests orchestration decisions:
 * - Empty results → early return
 * - Overflow threshold → bulk sync dispatch
 * - Normal path → per-item dispatch + cursor advancement
 * - Cursor resolution (null, stale, fresh)
 */
#[CoversClass(SyncStockItemWithCursorUseCase::class)]
final class SyncStockItemWithCursorUseCaseTest extends TestCase
{
    private StockDashboardsClientInterface&MockInterface $dashboardsClient;

    private SyncCursorRepositoryInterface&MockInterface $cursorRepository;

    private LoggerInterface&MockInterface $logger;

    private SyncStockItemWithCursorUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->dashboardsClient = Mockery::mock(StockDashboardsClientInterface::class);
        $this->cursorRepository = Mockery::mock(SyncCursorRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

        $this->useCase = new SyncStockItemWithCursorUseCase(
            $this->dashboardsClient,
            $this->cursorRepository,
            $this->logger,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Empty Results Branch
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_early_when_no_modified_items(): void
    {
        $this->cursorRepository->shouldReceive('getLastSyncDate')
            ->once()
            ->with(SyncCursorType::LinnworksStockItemFull)
            ->andReturn(new DateTimeImmutable('2026-03-15 10:00:00'));

        $this->dashboardsClient->shouldReceive('getModifiedStockItemIdsSince')
            ->once()
            ->andReturn([]);

        $this->cursorRepository->shouldNotReceive('updateLastSyncDate');

        $this->logger->shouldReceive('info')
            ->once()
            ->withArgs(static fn(string $msg): bool => \str_contains($msg, 'no changes since cursor'));

        $this->useCase->execute();
    }

    /*
    |--------------------------------------------------------------------------
    | Overflow Threshold Branch
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_dispatches_bulk_sync_when_overflow_threshold_reached(): void
    {
        Queue::fake();

        $this->cursorRepository->shouldReceive('getLastSyncDate')
            ->andReturn(new DateTimeImmutable('2026-03-15 10:00:00'));

        // Return exactly 500 items (overflow threshold)
        $items = $this->createModifiedItems(500);
        $this->dashboardsClient->shouldReceive('getModifiedStockItemIdsSince')
            ->once()
            ->andReturn($items);

        // Cursor should advance to now (not to last item's date)
        $this->cursorRepository->shouldReceive('updateLastSyncDate')
            ->once()
            ->withArgs(static function (SyncCursorType $type, DateTimeImmutable $date): bool {
                $diff = \abs((new DateTimeImmutable())->getTimestamp() - $date->getTimestamp());

                return $type === SyncCursorType::LinnworksStockItemFull && $diff <= 5;
            });

        $this->logger->shouldReceive('warning')
            ->once()
            ->withArgs(static fn(string $msg): bool => \str_contains($msg, 'hit threshold'));

        $this->useCase->execute();
    }

    /*
    |--------------------------------------------------------------------------
    | Normal Path — Per-Item Dispatch
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_dispatches_per_item_jobs_and_advances_cursor(): void
    {
        Queue::fake();

        $cursor = new DateTimeImmutable('2026-03-15 10:00:00');
        $newestDate = new DateTimeImmutable('2026-03-15 10:05:00');

        $this->cursorRepository->shouldReceive('getLastSyncDate')
            ->andReturn($cursor);

        $items = [
            new ModifiedStockItemDTO(
                Guid::fromTrusted('00000000-0000-0000-0000-000000000001'),
                new DateTimeImmutable('2026-03-15 10:03:00'),
            ),
            new ModifiedStockItemDTO(
                Guid::fromTrusted('00000000-0000-0000-0000-000000000002'),
                $newestDate,
            ),
        ];

        $this->dashboardsClient->shouldReceive('getModifiedStockItemIdsSince')
            ->once()
            ->with($cursor)
            ->andReturn($items);

        // Cursor advances to last element's modifiedDate (newest)
        $this->cursorRepository->shouldReceive('updateLastSyncDate')
            ->once()
            ->with(SyncCursorType::LinnworksStockItemFull, $newestDate);

        $this->logger->shouldReceive('info')
            ->once()
            ->withArgs(static fn(string $msg, array $ctx): bool => \str_contains($msg, 'completed')
                && $ctx['dispatched'] === 2);

        $this->useCase->execute();
    }

    #[Test]
    public function it_dispatches_single_item_job_and_advances_cursor(): void
    {
        Queue::fake();

        $itemDate = new DateTimeImmutable('2026-03-15 10:03:00');

        $this->cursorRepository->shouldReceive('getLastSyncDate')
            ->andReturn(new DateTimeImmutable('2026-03-15 10:00:00'));

        $this->dashboardsClient->shouldReceive('getModifiedStockItemIdsSince')
            ->andReturn([
                new ModifiedStockItemDTO(Guid::fromTrusted('00000000-0000-0000-0000-000000000001'), $itemDate),
            ]);

        $this->cursorRepository->shouldReceive('updateLastSyncDate')
            ->once()
            ->with(SyncCursorType::LinnworksStockItemFull, $itemDate);

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

        $this->dashboardsClient->shouldReceive('getModifiedStockItemIdsSince')
            ->once()
            ->withArgs(static function (DateTimeImmutable $since): bool {
                // Default lookback is 24 hours
                $diff = (new DateTimeImmutable())->getTimestamp() - $since->getTimestamp();

                return $diff >= 86_300 && $diff <= 86_500; // 24h ± ~100s
            })
            ->andReturn([]);

        $this->useCase->execute();
    }

    #[Test]
    public function it_caps_stale_cursor_to_max_lookback(): void
    {
        // Cursor is 7 days old — should be capped to 48 hours
        $staleCursor = new DateTimeImmutable('-7 days');

        $this->cursorRepository->shouldReceive('getLastSyncDate')
            ->andReturn($staleCursor);

        $this->dashboardsClient->shouldReceive('getModifiedStockItemIdsSince')
            ->once()
            ->withArgs(static function (DateTimeImmutable $since): bool {
                // Max lookback is 48 hours
                $diff = (new DateTimeImmutable())->getTimestamp() - $since->getTimestamp();

                return $diff >= 172_700 && $diff <= 172_900; // 48h ± ~100s
            })
            ->andReturn([]);

        $this->logger->shouldReceive('warning')
            ->once()
            ->withArgs(static fn(string $msg): bool => \str_contains($msg, 'stale'));

        $this->useCase->execute();
    }

    #[Test]
    public function it_uses_cursor_as_is_when_within_max_lookback(): void
    {
        $recentCursor = new DateTimeImmutable('-2 hours');

        $this->cursorRepository->shouldReceive('getLastSyncDate')
            ->andReturn($recentCursor);

        $this->dashboardsClient->shouldReceive('getModifiedStockItemIdsSince')
            ->once()
            ->with($recentCursor)
            ->andReturn([]);

        $this->useCase->execute();
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @return list<ModifiedStockItemDTO>
     */
    private function createModifiedItems(int $count): array
    {
        $items = [];

        for ($i = 0; $i < $count; $i++) {
            $items[] = new ModifiedStockItemDTO(
                Guid::fromTrusted(\sprintf('00000000-0000-0000-0000-%012d', $i)),
                new DateTimeImmutable(\sprintf('2026-03-15 10:%02d:%02d', (int) ($i / 60), $i % 60)),
            );
        }

        return $items;
    }
}
