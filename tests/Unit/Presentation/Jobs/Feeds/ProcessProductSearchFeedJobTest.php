<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Jobs\Feeds;

use App\Application\Feeds\ProcessProductSearchFeedUseCase;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\MalformedFeedDataException;
use App\Domain\Exceptions\Infrastructure\StorageOperationFailedException;
use App\Presentation\Jobs\Feeds\ProcessProductSearchFeedJob;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * ProcessProductSearchFeedJob Unit Tests.
 *
 * Tests the job's exception handling and retry logic:
 * - Success path with logging
 * - Permanent failures (InvalidArgumentException, MalformedFeedDataException) → fail immediately
 * - Transient failures with custom retry (ExternalServiceUnavailableException) → release with delay
 * - Transient failures for Laravel retry (StorageOperationFailedException) → rethrow
 * - Failed callback logging
 */
#[CoversClass(ProcessProductSearchFeedJob::class)]
final class ProcessProductSearchFeedJobTest extends TestCase
{
    private ProcessProductSearchFeedUseCase&MockInterface $mockUseCase;
    private ProcessProductSearchFeedJob $job;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->mockUseCase = Mockery::mock(ProcessProductSearchFeedUseCase::class);
        $this->job = new ProcessProductSearchFeedJob();
    }

    /*
    |--------------------------------------------------------------------------
    | Success Path
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_executes_use_case_and_logs_success(): void
    {
        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andReturnNull();

        Log::shouldReceive('info')
            ->once()
            ->with('Product search feed processing job starting');

        Log::shouldReceive('info')
            ->once()
            ->with('Product search feed processing job completed');

        $this->job->handle($this->mockUseCase);
    }

    /*
    |--------------------------------------------------------------------------
    | Permanent Failures - InvalidArgumentException
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_fails_immediately_on_invalid_argument_exception(): void
    {
        $exception = new InvalidArgumentException('Invalid source URL provided');

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        Log::shouldReceive('info')->once(); // starting log

        Log::shouldReceive('critical')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => $message === 'Unexpected exception in product feed job - code update required'
                    && \str_contains($context['job'], 'ProcessProductSearchFeedJob')
                    && $context['exception'] === InvalidArgumentException::class
                    && $context['message'] === 'Invalid source URL provided'
                    && \array_key_exists('attempts', $context));

        // Create a partial mock to verify fail() is called
        $job = Mockery::mock(ProcessProductSearchFeedJob::class)->makePartial();
        $job->shouldReceive('fail')
            ->once()
            ->with($exception);
        $job->shouldReceive('attempts')->andReturn(1);

        $this->expectException(InvalidArgumentException::class);

        $job->handle($this->mockUseCase);
    }

    /*
    |--------------------------------------------------------------------------
    | Permanent Failures - MalformedFeedDataException
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_fails_immediately_on_malformed_feed_exception(): void
    {
        $exception = new MalformedFeedDataException(
            feedName: 'Doofinder Feed',
            reason: 'Missing required title element',
        );

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        Log::shouldReceive('info')->once();

        Log::shouldReceive('critical')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => $message === 'Unexpected exception in product feed job - code update required'
                    && \str_contains($context['job'], 'ProcessProductSearchFeedJob')
                    && $context['exception'] === MalformedFeedDataException::class
                    && \str_contains($context['message'], 'Missing required title element')
                    && \array_key_exists('attempts', $context));

        $job = Mockery::mock(ProcessProductSearchFeedJob::class)->makePartial();
        $job->shouldReceive('fail')
            ->once()
            ->with($exception);
        $job->shouldReceive('attempts')->andReturn(1);

        $this->expectException(MalformedFeedDataException::class);

        $job->handle($this->mockUseCase);
    }

    /*
    |--------------------------------------------------------------------------
    | Transient Failures - ExternalServiceUnavailableException with retryAfter
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_releases_with_custom_delay_when_service_unavailable_with_retry_after(): void
    {
        $exception = new ExternalServiceUnavailableException(
            serviceName: 'Doofinder Feed',
            retryAfter: 120,
        );

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        Log::shouldReceive('info')->once();

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => $message === 'Source feed unavailable, will retry'
                    && $context['service'] === 'Doofinder Feed'
                    && $context['retry_after'] === 120
                    && \array_key_exists('attempts', $context));

        $job = Mockery::mock(ProcessProductSearchFeedJob::class)->makePartial();
        $job->shouldReceive('release')
            ->once()
            ->with(120);
        $job->shouldReceive('attempts')->andReturn(1);

        $job->handle($this->mockUseCase);
    }

    /*
    |--------------------------------------------------------------------------
    | Transient Failures - ExternalServiceUnavailableException without retryAfter
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_rethrows_service_unavailable_exception_without_retry_after(): void
    {
        $exception = new ExternalServiceUnavailableException(
            serviceName: 'Doofinder Feed',
            retryAfter: null,
        );

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        Log::shouldReceive('info')->once();

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => $message === 'Source feed unavailable, will retry'
                    && $context['service'] === 'Doofinder Feed'
                    && $context['retry_after'] === 'using backoff'
                    && \array_key_exists('attempts', $context));

        $job = Mockery::mock(ProcessProductSearchFeedJob::class)->makePartial();
        $job->shouldNotReceive('release');
        $job->shouldReceive('attempts')->andReturn(1);

        $this->expectException(ExternalServiceUnavailableException::class);

        $job->handle($this->mockUseCase);
    }

    /*
    |--------------------------------------------------------------------------
    | Transient Failures - StorageOperationFailedException
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_rethrows_storage_exception_for_laravel_retry(): void
    {
        $exception = new StorageOperationFailedException(
            operation: 'put',
            path: 'feeds/output.xml',
            reason: 'S3 connection timeout',
        );

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        Log::shouldReceive('info')->once();

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => $message === 'Storage operation failed, will retry'
                    && \str_contains($context['message'], 'S3 connection timeout')
                    && \array_key_exists('attempts', $context));

        $job = Mockery::mock(ProcessProductSearchFeedJob::class)->makePartial();
        $job->shouldReceive('attempts')->andReturn(2);

        $this->expectException(StorageOperationFailedException::class);

        $job->handle($this->mockUseCase);
    }

    /*
    |--------------------------------------------------------------------------
    | Failed Callback
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_logs_critical_on_permanent_failure(): void
    {
        $exception = new RuntimeException('Unexpected error after all retries');

        Log::shouldReceive('critical')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => $message === 'Product search feed processing job failed permanently'
                    && $context['exception'] === RuntimeException::class
                    && $context['message'] === 'Unexpected error after all retries'
                    && \array_key_exists('attempts', $context));

        $job = Mockery::mock(ProcessProductSearchFeedJob::class)->makePartial();
        $job->shouldReceive('attempts')->andReturn(3);

        $job->failed($exception);
    }

    #[Test]
    public function it_includes_correct_exception_class_in_failed_log(): void
    {
        $exception = new ExternalServiceUnavailableException(
            serviceName: 'Doofinder Feed',
            retryAfter: 60,
        );

        Log::shouldReceive('critical')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => $context['exception'] === ExternalServiceUnavailableException::class);

        $job = Mockery::mock(ProcessProductSearchFeedJob::class)->makePartial();
        $job->shouldReceive('attempts')->andReturn(3);

        $job->failed($exception);
    }
}
