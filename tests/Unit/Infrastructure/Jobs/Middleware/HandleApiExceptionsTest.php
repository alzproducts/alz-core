<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Jobs\Middleware;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

#[CoversClass(HandleApiExceptions::class)]
final class HandleApiExceptionsTest extends TestCase
{
    private HandleApiExceptions $middleware;

    private MockInterface $mockJob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->middleware = new HandleApiExceptions();
        $this->mockJob = Mockery::mock('JobWithInteractsWithQueue');
    }

    #[Test]
    public function it_passes_through_when_no_exception_occurs(): void
    {
        $called = false;

        $this->mockJob->shouldNotReceive('fail');
        $this->mockJob->shouldNotReceive('release');

        $this->middleware->handle($this->mockJob, static function () use (&$called): void {
            $called = true;
        });

        $this->assertTrue($called);
    }

    #[Test]
    public function it_releases_with_retry_after_on_transient_failure(): void
    {
        $exception = new ExternalServiceUnavailableException('Linnworks', retryAfter: 120);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static fn(string $msg, array $ctx): bool => $msg === 'Job transient failure, releasing for retry'
                && $ctx['service'] === 'Linnworks'
                && $ctx['retry_after'] === 120);

        $this->mockJob->shouldReceive('release')
            ->once()
            ->with(120);
        $this->mockJob->shouldNotReceive('fail');

        $this->middleware->handle($this->mockJob, static function () use ($exception): void {
            throw $exception;
        });
    }

    #[Test]
    public function it_rethrows_transient_failure_without_retry_after(): void
    {
        $exception = new ExternalServiceUnavailableException('Linnworks');

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static fn(string $msg, array $ctx): bool => $msg === 'Job transient failure, releasing for retry'
                && $ctx['service'] === 'Linnworks'
                && $ctx['retry_after'] === null);

        $this->mockJob->shouldNotReceive('release');
        $this->mockJob->shouldNotReceive('fail');

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->middleware->handle($this->mockJob, static function () use ($exception): void {
            throw $exception;
        });
    }

    #[Test]
    public function it_fails_immediately_on_permanent_api_failure(): void
    {
        $exception = new ResourceNotFoundException('Linnworks', 'Order', 'test-id');

        $this->mockJob->shouldReceive('fail')
            ->once()
            ->with($exception);
        $this->mockJob->shouldNotReceive('release');

        $this->middleware->handle($this->mockJob, static function () use ($exception): void {
            throw $exception;
        });
    }

    #[Test]
    public function it_rethrows_record_not_found_exception_without_failing_job(): void
    {
        $exception = new RecordNotFoundException('ProductVariation', 12345);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static fn(string $msg, array $ctx): bool => $msg === 'Job transient failure, releasing for retry'
                && $ctx['service'] === 'Database'
                && $ctx['retry_after'] === null);

        $this->mockJob->shouldNotReceive('release');
        $this->mockJob->shouldNotReceive('fail');

        $this->expectException(RecordNotFoundException::class);

        $this->middleware->handle($this->mockJob, static function () use ($exception): void {
            throw $exception;
        });
    }

    #[Test]
    public function it_lets_unexpected_exceptions_bubble_to_worker(): void
    {
        $exception = new RuntimeException('Unexpected database error');

        $this->mockJob->shouldNotReceive('fail');
        $this->mockJob->shouldNotReceive('release');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected database error');

        $this->middleware->handle($this->mockJob, static function () use ($exception): void {
            throw $exception;
        });
    }
}
