<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Jobs\Linnworks;

use App\Infrastructure\Jobs\Linnworks\SyncLinnworksOrdersJob;
use App\Application\Linnworks\Enums\OrderSyncTier;
use App\Application\Linnworks\UseCases\SyncLinnworksOrdersUseCase;
use App\Application\Results\SyncResult;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * SyncLinnworksOrdersJob Unit Tests.
 *
 * Tests Pattern A exception handling:
 * - Success path (delegates to use case with correct tier fromDate)
 * - Transient failures with retryAfter -> release
 * - Transient failures without retryAfter -> rethrow
 * - Permanent failures -> fail immediately
 * - Unexpected exceptions -> fail immediately
 * - Failed callback logging (API vs non-API)
 * - UniqueId per tier
 */
#[CoversClass(SyncLinnworksOrdersJob::class)]
final class SyncLinnworksOrdersJobTest extends TestCase
{
    private SyncLinnworksOrdersUseCase&MockInterface $mockUseCase;

    private LoggerInterface&MockInterface $mockLogger;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->mockUseCase = Mockery::mock(SyncLinnworksOrdersUseCase::class);
        $this->mockLogger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
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
                => \abs((new DateTimeImmutable())->getTimestamp() - $fromDate->getTimestamp() - 3600) < 5)
            ->andReturn(new SyncResult(fetched: 10, saved: 10, failed: 0));

        $job = $this->createJobMock(OrderSyncTier::Hourly);
        $job->handle($this->mockUseCase, $this->mockLogger);
    }

    #[Test]
    public function it_logs_completion_with_results(): void
    {
        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andReturn(new SyncResult(fetched: 5, saved: 4, failed: 1));

        $this->mockLogger->shouldReceive('info')
            ->once()
            ->withArgs(static fn(string $msg, array $ctx): bool => $msg === 'Linnworks order sync job starting'
                && $ctx['tier'] === 'daily');

        $this->mockLogger->shouldReceive('info')
            ->once()
            ->withArgs(static fn(string $msg, array $ctx): bool => $msg === 'Linnworks order sync job completed'
                && $ctx['tier'] === 'daily'
                && $ctx['fetched'] === 5
                && $ctx['saved'] === 4
                && $ctx['failed'] === 1);

        $job = $this->createJobMock(OrderSyncTier::Daily);
        $job->handle($this->mockUseCase, $this->mockLogger);
    }

    /*
    |--------------------------------------------------------------------------
    | Transient Failures
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_releases_with_api_provided_retry_after(): void
    {
        $exception = new ExternalServiceUnavailableException('Linnworks', retryAfter: 120);

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        $job = $this->createJobMock(OrderSyncTier::Hourly);
        $job->shouldReceive('release')
            ->once()
            ->with(120);

        $job->handle($this->mockUseCase, $this->mockLogger);
    }

    #[Test]
    public function it_rethrows_transient_exception_when_retry_after_is_null(): void
    {
        $exception = new ExternalServiceUnavailableException('Linnworks');

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        $job = $this->createJobMock(OrderSyncTier::Hourly);
        $job->shouldNotReceive('release');

        $this->expectException(ExternalServiceUnavailableException::class);

        $job->handle($this->mockUseCase, $this->mockLogger);
    }

    /*
    |--------------------------------------------------------------------------
    | Permanent Failures
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_fails_immediately_on_permanent_api_failure(): void
    {
        $exception = new ResourceNotFoundException('Linnworks', 'Order', 'test-id');

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        $job = $this->createJobMock(OrderSyncTier::Daily);
        $job->shouldReceive('fail')
            ->once()
            ->with($exception);
        $job->shouldNotReceive('release');

        $this->expectException(ResourceNotFoundException::class);

        $job->handle($this->mockUseCase, $this->mockLogger);
    }

    /*
    |--------------------------------------------------------------------------
    | Unexpected Exceptions
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_fails_immediately_on_unexpected_exception(): void
    {
        $exception = new RuntimeException('Unexpected error');

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        $job = $this->createJobMock(OrderSyncTier::Weekly);
        $job->shouldReceive('fail')
            ->once()
            ->with($exception);

        $this->expectException(RuntimeException::class);

        $job->handle($this->mockUseCase, $this->mockLogger);
    }

    /*
    |--------------------------------------------------------------------------
    | Failed Callback
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_logs_error_for_api_exception_on_failure(): void
    {
        $exception = new ExternalServiceUnavailableException('Linnworks');

        Log::shouldReceive('error')
            ->once()
            ->withArgs(static fn(string $msg, array $ctx): bool => $msg === 'Linnworks order sync job failed permanently'
                && $ctx['tier'] === 'hourly'
                && $ctx['exception'] === ExternalServiceUnavailableException::class);

        $job = $this->createJobMock(OrderSyncTier::Hourly);
        $job->shouldReceive('attempts')->andReturn(2);

        $job->failed($exception);
    }

    #[Test]
    public function it_logs_critical_for_non_api_exception_on_failure(): void
    {
        $exception = new RuntimeException('Database crashed');

        Log::shouldReceive('critical')
            ->once()
            ->withArgs(static fn(string $msg, array $ctx): bool => $msg === 'Linnworks order sync job failed permanently'
                && $ctx['tier'] === 'daily'
                && $ctx['exception'] === RuntimeException::class);

        $job = $this->createJobMock(OrderSyncTier::Daily);
        $job->shouldReceive('attempts')->andReturn(2);

        $job->failed($exception);
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
