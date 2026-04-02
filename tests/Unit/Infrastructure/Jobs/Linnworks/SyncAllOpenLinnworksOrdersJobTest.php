<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Jobs\Linnworks;

use App\Application\Contracts\Linnworks\OrderDashboardsClientInterface;
use App\Application\Linnworks\UseCases\BackfillLinnworksOrdersUseCase;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Jobs\Linnworks\SyncAllOpenLinnworksOrdersJob;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SyncAllOpenLinnworksOrdersJob Unit Tests.
 *
 * Tests:
 * - Job properties (uniqueId)
 * - Middleware configuration
 * - handle() — delegates to use case when IDs are found
 * - handle() — skips use case when no open orders exist
 */
#[CoversClass(SyncAllOpenLinnworksOrdersJob::class)]
final class SyncAllOpenLinnworksOrdersJobTest extends TestCase
{
    private BackfillLinnworksOrdersUseCase&MockInterface $mockUseCase;

    private OrderDashboardsClientInterface&MockInterface $mockDashboardsClient;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->mockUseCase = Mockery::mock(BackfillLinnworksOrdersUseCase::class);
        $this->mockDashboardsClient = Mockery::mock(OrderDashboardsClientInterface::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Job Properties
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_has_fixed_unique_id(): void
    {
        $job = new SyncAllOpenLinnworksOrdersJob();
        $this->assertSame('sync-all-open-linnworks-orders', $job->uniqueId());
    }

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_correct_middleware(): void
    {
        $job = new SyncAllOpenLinnworksOrdersJob();
        $middleware = $job->middleware();

        $this->assertCount(2, $middleware);
        $this->assertInstanceOf(ThrottlesExceptions::class, $middleware[0]);
        $this->assertInstanceOf(HandleApiExceptions::class, $middleware[1]);
    }

    /*
    |--------------------------------------------------------------------------
    | Success Path
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_delegates_to_use_case_when_open_orders_exist(): void
    {
        $orderIds = [new Guid('550e8400-e29b-41d4-a716-446655440000')];

        $this->mockDashboardsClient
            ->shouldReceive('getOpenOrderIds')
            ->once()
            ->andReturn($orderIds);

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->with($orderIds);

        $job = $this->createJobMock();
        $job->handle($this->mockUseCase, $this->mockDashboardsClient);
    }

    #[Test]
    public function it_skips_use_case_when_no_open_orders(): void
    {
        $this->mockDashboardsClient
            ->shouldReceive('getOpenOrderIds')
            ->once()
            ->andReturn([]);

        $this->mockUseCase
            ->shouldNotReceive('execute');

        $job = $this->createJobMock();
        $job->handle($this->mockUseCase, $this->mockDashboardsClient);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function createJobMock(): SyncAllOpenLinnworksOrdersJob&MockInterface
    {
        $job = Mockery::mock(SyncAllOpenLinnworksOrdersJob::class)->makePartial();
        $job->allows('onQueue');
        $job->__construct();

        return $job;
    }
}
