<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Jobs\Linnworks;

use App\Application\Linnworks\UseCases\SyncStockItemWithCursorUseCase;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Infrastructure\Jobs\Linnworks\SyncStockItemsWithCursorJob;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * SyncStockItemsWithCursorJob Unit Tests.
 *
 * Tests Pattern A exception handling for the scheduled orchestrator job.
 */
#[CoversClass(SyncStockItemsWithCursorJob::class)]
final class SyncStockItemsWithCursorJobTest extends TestCase
{
    private SyncStockItemWithCursorUseCase&MockInterface $mockUseCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->mockUseCase = Mockery::mock(SyncStockItemWithCursorUseCase::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Success Path
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_delegates_to_use_case(): void
    {
        $this->mockUseCase
            ->shouldReceive('execute')
            ->once();

        $job = $this->createJob();
        $job->handle($this->mockUseCase);
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

        $job->handle($this->mockUseCase);
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

        $job->handle($this->mockUseCase);
    }

    /*
    |--------------------------------------------------------------------------
    | Permanent Failures
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_fails_immediately_on_permanent_api_failure(): void
    {
        $exception = new ResourceNotFoundException('Linnworks', 'StockItem', 'test-id');

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        $job = $this->createJobMock();
        $job->shouldReceive('fail')
            ->once()
            ->with($exception);

        $this->expectException(ResourceNotFoundException::class);

        $job->handle($this->mockUseCase);
    }

    /*
    |--------------------------------------------------------------------------
    | Unexpected Exceptions
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_fails_immediately_on_unexpected_exception(): void
    {
        $exception = new RuntimeException('Unexpected');

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        $job = $this->createJobMock();
        $job->shouldReceive('fail')
            ->once()
            ->with($exception);

        $this->expectException(RuntimeException::class);

        $job->handle($this->mockUseCase);
    }

    /*
    |--------------------------------------------------------------------------
    | Failed Callback
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_logs_critical_for_non_api_exception_on_failure(): void
    {
        $exception = new RuntimeException('Unexpected crash');

        Log::shouldReceive('critical')
            ->once()
            ->withArgs(static fn(string $msg, array $ctx): bool => $msg === 'Stock item cursor sync job failed permanently'
                && $ctx['exception'] === RuntimeException::class);

        $job = $this->createJobMock();
        $job->shouldReceive('attempts')->andReturn(2);

        $job->failed($exception);
    }

    #[Test]
    public function it_logs_error_for_api_exception_on_failure(): void
    {
        $exception = new ExternalServiceUnavailableException('Linnworks');

        Log::shouldReceive('error')
            ->once()
            ->withArgs(static fn(string $msg, array $ctx): bool => $msg === 'Stock item cursor sync job failed permanently'
                && $ctx['exception'] === ExternalServiceUnavailableException::class);

        $job = $this->createJobMock();
        $job->shouldReceive('attempts')->andReturn(2);

        $job->failed($exception);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function createJob(): SyncStockItemsWithCursorJob
    {
        return new SyncStockItemsWithCursorJob();
    }

    private function createJobMock(): SyncStockItemsWithCursorJob&MockInterface
    {
        $job = Mockery::mock(SyncStockItemsWithCursorJob::class)->makePartial();
        $job->allows('onQueue');
        $job->__construct();

        return $job;
    }
}
