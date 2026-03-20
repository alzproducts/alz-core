<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Jobs\Linnworks;

use App\Infrastructure\Jobs\Linnworks\SyncStockItemJob;
use App\Application\Linnworks\UseCases\SyncStockItemUseCase;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\ValueObjects\Guid;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * SyncStockItemJob Unit Tests.
 *
 * Tests Pattern A exception handling:
 * - Success path (delegates to use case)
 * - Transient failures with retryAfter → release
 * - Transient failures without retryAfter → rethrow
 * - Permanent failures → fail immediately
 * - Unexpected exceptions → fail immediately
 * - Failed callback logging
 */
#[CoversClass(SyncStockItemJob::class)]
final class SyncStockItemJobTest extends TestCase
{
    private SyncStockItemUseCase&MockInterface $mockUseCase;

    private Guid $stockItemId;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->mockUseCase = Mockery::mock(SyncStockItemUseCase::class);
        $this->stockItemId = Guid::fromTrusted('51d3090b-6a61-4dac-8ef5-b9f6f38082fa');
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
            ->once()
            ->with($this->stockItemId);

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
        $exception = new ExternalServiceUnavailableException('Linnworks', retryAfter: 120);

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        $job = $this->createJobMock();
        $job->shouldReceive('release')
            ->once()
            ->with(120);

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
        $job->shouldNotReceive('release');

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
        $exception = new RuntimeException('Database crashed');

        Log::shouldReceive('critical')
            ->once()
            ->withArgs(static fn(string $msg, array $ctx): bool => $msg === 'Stock item sync job failed permanently'
                && $ctx['stock_item_id'] === '51d3090b-6a61-4dac-8ef5-b9f6f38082fa'
                && $ctx['exception'] === RuntimeException::class);

        $job = $this->createJobMock();
        $job->shouldReceive('attempts')->andReturn(3);

        $job->failed($exception);
    }

    #[Test]
    public function it_logs_error_for_api_exception_on_failure(): void
    {
        $exception = new ExternalServiceUnavailableException('Linnworks');

        Log::shouldReceive('error')
            ->once()
            ->withArgs(static fn(string $msg, array $ctx): bool => $msg === 'Stock item sync job failed permanently'
                && $ctx['exception'] === ExternalServiceUnavailableException::class);

        $job = $this->createJobMock();
        $job->shouldReceive('attempts')->andReturn(3);

        $job->failed($exception);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function createJob(): SyncStockItemJob
    {
        return new SyncStockItemJob($this->stockItemId);
    }

    private function createJobMock(): SyncStockItemJob&MockInterface
    {
        $job = Mockery::mock(SyncStockItemJob::class)->makePartial();
        $job->allows('onQueue');
        $job->__construct($this->stockItemId);

        return $job;
    }
}
