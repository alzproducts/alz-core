<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Jobs\Linnworks;

use App\Application\Linnworks\Enums\OrderSyncTier;
use App\Application\Linnworks\UseCases\SyncLinnworksOrdersUseCase;
use App\Infrastructure\Jobs\Linnworks\SyncLinnworksOrdersJob;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SyncLinnworksOrdersJob Unit Tests.
 *
 * Tests:
 * - Job properties (uniqueId per tier, tier property)
 * - Middleware configuration
 * - Success path (delegates to use case with correct tier fromDate)
 * Exception handling (transient/permanent/unexpected) is tested in HandleApiExceptionsTest.
 * Failed callback logging is handled by the middleware.
 */
#[CoversClass(SyncLinnworksOrdersJob::class)]
final class SyncLinnworksOrdersJobTest extends TestCase
{
    private SyncLinnworksOrdersUseCase&MockInterface $mockUseCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->mockUseCase = Mockery::mock(SyncLinnworksOrdersUseCase::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Job Properties
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_includes_tier_in_unique_id(): void
    {
        $job = new SyncLinnworksOrdersJob(OrderSyncTier::Hourly);
        $this->assertSame('sync-linnworks-orders-hourly', $job->uniqueId());

        $job = new SyncLinnworksOrdersJob(OrderSyncTier::Daily);
        $this->assertSame('sync-linnworks-orders-daily', $job->uniqueId());

        $job = new SyncLinnworksOrdersJob(OrderSyncTier::Weekly);
        $this->assertSame('sync-linnworks-orders-weekly', $job->uniqueId());

        $job = new SyncLinnworksOrdersJob(OrderSyncTier::Monthly);
        $this->assertSame('sync-linnworks-orders-monthly', $job->uniqueId());
    }

    #[Test]
    public function it_stores_tier_as_public_property(): void
    {
        $job = new SyncLinnworksOrdersJob(OrderSyncTier::Daily);
        $this->assertSame(OrderSyncTier::Daily, $job->tier);
    }

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_correct_middleware(): void
    {
        $job = new SyncLinnworksOrdersJob(OrderSyncTier::Hourly);
        $middleware = $job->middleware();

        $this->assertCount(2, $middleware);
        $this->assertInstanceOf(ServiceCircuitBreaker::class, $middleware[0]);
        $this->assertInstanceOf(HandleApiExceptions::class, $middleware[1]);
    }

    /*
    |--------------------------------------------------------------------------
    | Success Path
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_delegates_to_use_case_with_tier_from_date(): void
    {
        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->withArgs(static fn(DateTimeImmutable $fromDate): bool
                // Hourly tier = 1 hour lookback
                => \abs((new DateTimeImmutable())->getTimestamp() - $fromDate->getTimestamp() - 3600) < 5);

        $job = $this->createJobMock(OrderSyncTier::Hourly);
        $job->handle($this->mockUseCase);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function createJobMock(OrderSyncTier $tier): SyncLinnworksOrdersJob&MockInterface
    {
        $job = Mockery::mock(SyncLinnworksOrdersJob::class)->makePartial();
        $job->allows('onQueue');
        $job->__construct($tier);

        return $job;
    }
}
