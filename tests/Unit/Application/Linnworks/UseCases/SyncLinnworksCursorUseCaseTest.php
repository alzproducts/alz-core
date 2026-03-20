<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Linnworks\UseCases;

use App\Application\Contracts\Inventory\SyncCursorRepositoryInterface;
use App\Application\Enums\SyncCursorType;
use App\Application\Linnworks\UseCases\SyncLinnworksCursorUseCase;
use App\Application\Linnworks\UseCases\SyncLinnworksOrdersUseCase;
use App\Application\Results\SyncResult;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * SyncLinnworksCursorUseCase Unit Tests.
 *
 * Tests cursor management orchestration:
 * - Cursor resolution (null, stale, fresh)
 * - Delegation to SyncLinnworksOrdersUseCase
 * - Cursor advancement on success
 * - No cursor advancement on empty results
 */
#[CoversClass(SyncLinnworksCursorUseCase::class)]
final class SyncLinnworksCursorUseCaseTest extends TestCase
{
    private SyncLinnworksOrdersUseCase&MockInterface $ordersUseCase;

    private SyncCursorRepositoryInterface&MockInterface $cursorRepository;

    private LoggerInterface&MockInterface $logger;

    private SyncLinnworksCursorUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->ordersUseCase = Mockery::mock(SyncLinnworksOrdersUseCase::class);
        $this->cursorRepository = Mockery::mock(SyncCursorRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

        $this->useCase = new SyncLinnworksCursorUseCase(
            $this->ordersUseCase,
            $this->cursorRepository,
            $this->logger,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Cursor Resolution: No Cursor (First Run)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_uses_default_24_hour_lookback_when_no_cursor_exists(): void
    {
        $this->cursorRepository->shouldReceive('getLastSyncDate')
            ->once()
            ->with(SyncCursorType::LinnworksOrdersCursor)
            ->andReturn(null);

        $this->ordersUseCase->shouldReceive('execute')
            ->once()
            ->withArgs(static function (DateTimeImmutable $fromDate): bool {
                // Default lookback is 24 hours
                $diff = (new DateTimeImmutable())->getTimestamp() - $fromDate->getTimestamp();

                return $diff >= 86_300 && $diff <= 86_500; // 24h +/- ~100s
            })
            ->andReturn(SyncResult::empty());

        $this->cursorRepository->shouldNotReceive('updateLastSyncDate');

        $this->useCase->execute();
    }

    /*
    |--------------------------------------------------------------------------
    | Cursor Resolution: Stale Cursor
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_caps_stale_cursor_to_max_2_hour_lookback(): void
    {
        // Cursor is 7 days old — should be capped to 2 hours
        $staleCursor = new DateTimeImmutable('-7 days');

        $this->cursorRepository->shouldReceive('getLastSyncDate')
            ->once()
            ->with(SyncCursorType::LinnworksOrdersCursor)
            ->andReturn($staleCursor);

        $this->ordersUseCase->shouldReceive('execute')
            ->once()
            ->withArgs(static function (DateTimeImmutable $fromDate): bool {
                // Max lookback is 2 hours
                $diff = (new DateTimeImmutable())->getTimestamp() - $fromDate->getTimestamp();

                return $diff >= 7_100 && $diff <= 7_300; // 2h +/- ~100s
            })
            ->andReturn(SyncResult::empty());

        $this->logger->shouldReceive('info')
            ->once()
            ->withArgs(static fn(string $msg): bool => \str_contains($msg, 'stale'));

        $this->useCase->execute();
    }

    /*
    |--------------------------------------------------------------------------
    | Cursor Resolution: Fresh Cursor
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_uses_cursor_as_is_when_within_max_lookback(): void
    {
        $recentCursor = new DateTimeImmutable('-30 minutes');

        $this->cursorRepository->shouldReceive('getLastSyncDate')
            ->once()
            ->with(SyncCursorType::LinnworksOrdersCursor)
            ->andReturn($recentCursor);

        $this->ordersUseCase->shouldReceive('execute')
            ->once()
            ->with($recentCursor)
            ->andReturn(SyncResult::empty());

        $this->useCase->execute();
    }

    /*
    |--------------------------------------------------------------------------
    | Cursor Advancement
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_advances_cursor_when_orders_have_latest_last_updated(): void
    {
        $cursor = new DateTimeImmutable('-1 hour');
        $latestLastUpdated = new DateTimeImmutable('2026-03-19 15:30:00');

        $syncResult = new SyncResult(
            fetched: 5,
            saved: 5,
            failed: 0,
            latestLastUpdated: $latestLastUpdated,
        );

        $this->cursorRepository->shouldReceive('getLastSyncDate')
            ->andReturn($cursor);

        $this->ordersUseCase->shouldReceive('execute')
            ->once()
            ->andReturn($syncResult);

        $this->cursorRepository->shouldReceive('updateLastSyncDate')
            ->once()
            ->with(SyncCursorType::LinnworksOrdersCursor, $latestLastUpdated);

        $this->logger->shouldReceive('info')
            ->once()
            ->withArgs(static fn(string $msg): bool => \str_contains($msg, 'cursor advanced'));

        $result = $this->useCase->execute();

        $this->assertSame(5, $result->fetched);
        $this->assertSame(5, $result->saved);
    }

    #[Test]
    public function it_does_not_advance_cursor_when_no_orders_found(): void
    {
        $cursor = new DateTimeImmutable('-1 hour');

        $this->cursorRepository->shouldReceive('getLastSyncDate')
            ->andReturn($cursor);

        $this->ordersUseCase->shouldReceive('execute')
            ->once()
            ->andReturn(SyncResult::empty());

        $this->cursorRepository->shouldNotReceive('updateLastSyncDate');

        $result = $this->useCase->execute();

        $this->assertTrue($result->isEmpty());
    }

    #[Test]
    public function it_does_not_advance_cursor_when_latest_last_updated_is_null(): void
    {
        $cursor = new DateTimeImmutable('-1 hour');

        // Orders were fetched but latestLastUpdated is null (edge case)
        $syncResult = new SyncResult(
            fetched: 3,
            saved: 0,
            failed: 3,
            latestLastUpdated: null,
            failedReferences: ['ref-1', 'ref-2', 'ref-3'],
        );

        $this->cursorRepository->shouldReceive('getLastSyncDate')
            ->andReturn($cursor);

        $this->ordersUseCase->shouldReceive('execute')
            ->once()
            ->andReturn($syncResult);

        $this->cursorRepository->shouldNotReceive('updateLastSyncDate');

        $result = $this->useCase->execute();

        $this->assertSame(3, $result->fetched);
        $this->assertSame(3, $result->failed);
    }

    /*
    |--------------------------------------------------------------------------
    | Return Value Passthrough
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_sync_result_from_orders_use_case(): void
    {
        $latestLastUpdated = new DateTimeImmutable('2026-03-19 16:00:00');
        $expected = new SyncResult(
            fetched: 10,
            saved: 8,
            failed: 2,
            latestLastUpdated: $latestLastUpdated,
            failedReferences: ['ref-1', 'ref-2'],
        );

        $this->cursorRepository->shouldReceive('getLastSyncDate')
            ->andReturn(new DateTimeImmutable('-30 minutes'));

        $this->ordersUseCase->shouldReceive('execute')
            ->once()
            ->andReturn($expected);

        $this->cursorRepository->shouldReceive('updateLastSyncDate')
            ->once()
            ->with(SyncCursorType::LinnworksOrdersCursor, $latestLastUpdated);

        $result = $this->useCase->execute();

        $this->assertSame(10, $result->fetched);
        $this->assertSame(8, $result->saved);
        $this->assertSame(2, $result->failed);
        $this->assertSame($latestLastUpdated, $result->latestLastUpdated);
        $this->assertSame(['ref-1', 'ref-2'], $result->failedReferences);
    }
}
