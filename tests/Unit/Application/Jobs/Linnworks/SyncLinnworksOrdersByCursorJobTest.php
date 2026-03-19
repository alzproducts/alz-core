<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Jobs\Linnworks;

use App\Application\Jobs\Linnworks\SyncLinnworksOrdersByCursorJob;
use App\Application\Linnworks\UseCases\SyncLinnworksCursorUseCase;
use App\Application\Results\SyncResult;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
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
 * SyncLinnworksOrdersByCursorJob Unit Tests.
 *
 * Tests Pattern A exception handling:
 * - Success path (delegates to cursor use case)
 * - Transient failures with retryAfter -> release
 * - Transient failures without retryAfter -> rethrow
 * - Permanent failures -> fail immediately
 * - Unexpected exceptions -> fail immediately
 * - Failed callback logging (API vs non-API)
 */
#[CoversClass(SyncLinnworksOrdersByCursorJob::class)]
final class SyncLinnworksOrdersByCursorJobTest extends TestCase
{
    private SyncLinnworksCursorUseCase&MockInterface $mockUseCase;

    private LoggerInterface&MockInterface $mockLogger;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->mockUseCase = Mockery::mock(SyncLinnworksCursorUseCase::class);
        $this->mockLogger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
    }

    /*
    |--------------------------------------------------------------------------
    | Job Properties
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_has_fixed_unique_id(): void
    {
        $job = new SyncLinnworksOrdersByCursorJob();
        $this->assertSame('sync-linnworks-orders-cursor', $job->uniqueId());
    }

    /*
    |--------------------------------------------------------------------------
    | Success Path
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_delegates_to_cursor_use_case(): void
    {
        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andReturn(new SyncResult(fetched: 3, saved: 3, failed: 0));

        $job = $this->createJobMock();
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
            ->withArgs(static fn(string $msg, array $ctx): bool => $msg === 'Linnworks cursor order sync job completed'
                && $ctx['fetched'] === 5
                && $ctx['saved'] === 4
                && $ctx['failed'] === 1);

        $job = $this->createJobMock();
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
        $exception = new ExternalServiceUnavailableException('Linnworks', retryAfter: 60);

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        $job = $this->createJobMock();
        $job->shouldReceive('release')
            ->once()
            ->with(60);

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

        $job = $this->createJobMock();
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

        $job = $this->createJobMock();
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

        $job = $this->createJobMock();
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
            ->withArgs(static fn(string $msg, array $ctx): bool => $msg === 'Linnworks cursor order sync job failed permanently'
                && $ctx['exception'] === ExternalServiceUnavailableException::class);

        $job = $this->createJobMock();
        $job->shouldReceive('attempts')->andReturn(2);

        $job->failed($exception);
    }

    #[Test]
    public function it_logs_critical_for_non_api_exception_on_failure(): void
    {
        $exception = new RuntimeException('Database crashed');

        Log::shouldReceive('critical')
            ->once()
            ->withArgs(static fn(string $msg, array $ctx): bool => $msg === 'Linnworks cursor order sync job failed permanently'
                && $ctx['exception'] === RuntimeException::class);

        $job = $this->createJobMock();
        $job->shouldReceive('attempts')->andReturn(2);

        $job->failed($exception);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function createJobMock(): SyncLinnworksOrdersByCursorJob&MockInterface
    {
        $job = Mockery::mock(SyncLinnworksOrdersByCursorJob::class)->makePartial();
        $job->allows('onQueue');
        $job->__construct();

        return $job;
    }
}
