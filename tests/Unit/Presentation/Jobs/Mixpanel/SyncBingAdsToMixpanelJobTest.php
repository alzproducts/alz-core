<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Jobs\Mixpanel;

use App\Application\AdSpend\UseCases\SyncAdSpendUseCase;
use App\Application\Jobs\Mixpanel\SyncBingAdsToMixpanelJob;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\PayloadSerializationException;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * SyncBingAdsToMixpanelJob Unit Tests.
 *
 * Tests the job's exception handling and retry logic:
 * - Success path with logging
 * - Permanent failures (PayloadSerializationException, AuthenticationExpiredException) → fail immediately
 * - Transient failures with custom retry (ExternalServiceUnavailableException) → release with delay
 * - Transient failures for Laravel retry (ExternalServiceUnavailableException without retryAfter) → rethrow
 * - Failed callback logging
 */
#[CoversClass(SyncBingAdsToMixpanelJob::class)]
final class SyncBingAdsToMixpanelJobTest extends TestCase
{
    private const string TEST_DATE = '2024-11-20';

    private SyncAdSpendUseCase&MockInterface $mockUseCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->mockUseCase = Mockery::mock(SyncAdSpendUseCase::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Success Path Tests
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
            ->with('Queued Bing Ads to Mixpanel sync starting', [
                'from' => self::TEST_DATE,
                'to' => self::TEST_DATE,
            ]);

        Log::shouldReceive('info')
            ->once()
            ->with('Queued Bing Ads to Mixpanel sync completed', [
                'from' => self::TEST_DATE,
                'to' => self::TEST_DATE,
            ]);

        $job = $this->createJob();
        $job->handle($this->mockUseCase);
    }

    #[Test]
    public function it_logs_start_message_with_date_range(): void
    {
        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andReturnNull();

        Log::shouldReceive('info')
            ->once()
            ->with('Queued Bing Ads to Mixpanel sync starting', [
                'from' => '2024-01-01',
                'to' => '2024-01-31',
            ]);

        Log::shouldReceive('info')
            ->once()
            ->with('Queued Bing Ads to Mixpanel sync completed', [
                'from' => '2024-01-01',
                'to' => '2024-01-31',
            ]);

        $job = new SyncBingAdsToMixpanelJob(
            new DateTimeImmutable('2024-01-01'),
            new DateTimeImmutable('2024-01-31'),
        );
        $job->handle($this->mockUseCase);
    }

    /*
    |--------------------------------------------------------------------------
    | Permanent Failures - PayloadSerializationException
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_fails_immediately_on_payload_serialization_exception(): void
    {
        $exception = new PayloadSerializationException('Mixpanel', 'JSON encoding failed');

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        Log::shouldReceive('info')->once();

        Log::shouldReceive('critical')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => $message === 'Payload serialization failed during Bing Ads sync, failing immediately'
                    && $context['service'] === 'Mixpanel'
                    && \str_contains($context['error'], 'JSON encoding failed')
                    && \array_key_exists('attempts', $context));

        $job = $this->createJobMock();
        $job->shouldReceive('fail')
            ->once()
            ->with($exception);
        $job->shouldReceive('attempts')->andReturn(1);

        $this->expectException(PayloadSerializationException::class);

        $job->handle($this->mockUseCase);
    }

    #[Test]
    public function it_does_not_retry_on_payload_serialization_exception(): void
    {
        $exception = new PayloadSerializationException('Mixpanel', 'Invalid data structure');

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        Log::shouldReceive('info')->once();
        Log::shouldReceive('critical')->once();

        $job = $this->createJobMock();
        $job->shouldReceive('fail')
            ->once()
            ->with($exception);
        $job->shouldNotReceive('release'); // Should NOT release for retry
        $job->shouldReceive('attempts')->andReturn(1);

        $this->expectException(PayloadSerializationException::class);

        $job->handle($this->mockUseCase);
    }

    /*
    |--------------------------------------------------------------------------
    | Permanent Failures - AuthenticationExpiredException
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_fails_immediately_on_authentication_expired_exception(): void
    {
        $exception = new AuthenticationExpiredException('Bing Ads');

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        Log::shouldReceive('info')->once();

        Log::shouldReceive('critical')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => $message === 'Authentication failed during Bing Ads sync, failing immediately'
                    && $context['service'] === 'Bing Ads'
                    && \str_contains($context['message'], 'Bing Ads')
                    && \array_key_exists('attempts', $context));

        $job = $this->createJobMock();
        $job->shouldReceive('fail')
            ->once()
            ->with($exception);
        $job->shouldReceive('attempts')->andReturn(1);

        $this->expectException(AuthenticationExpiredException::class);

        $job->handle($this->mockUseCase);
    }

    #[Test]
    public function it_does_not_retry_on_authentication_expired_exception(): void
    {
        $exception = new AuthenticationExpiredException('Bing Ads');

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        Log::shouldReceive('info')->once();
        Log::shouldReceive('critical')->once();

        $job = $this->createJobMock();
        $job->shouldReceive('fail')
            ->once()
            ->with($exception);
        $job->shouldNotReceive('release'); // Should NOT release for retry
        $job->shouldReceive('attempts')->andReturn(2);

        $this->expectException(AuthenticationExpiredException::class);

        $job->handle($this->mockUseCase);
    }

    /*
    |--------------------------------------------------------------------------
    | Transient Failures - ExternalServiceUnavailableException with retryAfter
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_releases_with_api_provided_retry_after(): void
    {
        $exception = new ExternalServiceUnavailableException('Bing Ads', retryAfter: 180);

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        Log::shouldReceive('info')->once();

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => $message === 'External service unavailable during Bing Ads sync, will retry'
                    && $context['service'] === 'Bing Ads'
                    && $context['retry_after'] === 180
                    && \array_key_exists('attempts', $context));

        $job = $this->createJobMock();
        $job->shouldReceive('release')
            ->once()
            ->with(180);
        $job->shouldReceive('attempts')->andReturn(1);

        $job->handle($this->mockUseCase);
    }

    #[Test]
    public function it_logs_warning_before_releasing_on_service_unavailable(): void
    {
        $exception = new ExternalServiceUnavailableException('Mixpanel', retryAfter: 60);

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        Log::shouldReceive('info')->once();

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => $context['from'] === self::TEST_DATE
                    && $context['to'] === self::TEST_DATE
                    && $context['service'] === 'Mixpanel'
                    && $context['retry_after'] === 60
                    && $context['attempts'] === 2);

        $job = $this->createJobMock();
        $job->shouldReceive('release')
            ->once()
            ->with(60);
        $job->shouldReceive('attempts')->andReturn(2);

        $job->handle($this->mockUseCase);
    }

    /*
    |--------------------------------------------------------------------------
    | Transient Failures - ExternalServiceUnavailableException without retryAfter
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_rethrows_exception_when_retry_after_is_null(): void
    {
        $exception = new ExternalServiceUnavailableException('Bing Ads');

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        Log::shouldReceive('info')->once();

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => $context['retry_after'] === 'using backoff');

        $job = $this->createJobMock();
        $job->shouldNotReceive('release');
        $job->shouldReceive('attempts')->andReturn(3);

        $this->expectException(ExternalServiceUnavailableException::class);

        $job->handle($this->mockUseCase);
    }

    #[Test]
    public function it_logs_using_backoff_when_retry_after_is_null(): void
    {
        $exception = new ExternalServiceUnavailableException('Bing Ads');

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        Log::shouldReceive('info')->once();

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => $message === 'External service unavailable during Bing Ads sync, will retry'
                    && $context['service'] === 'Bing Ads'
                    && $context['retry_after'] === 'using backoff'
                    && $context['attempts'] === 4);

        $job = $this->createJobMock();
        $job->shouldReceive('attempts')->andReturn(4);

        try {
            $job->handle($this->mockUseCase);
        } catch (ExternalServiceUnavailableException) {
            // Expected
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Unexpected Exception Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_fails_immediately_on_unexpected_exception(): void
    {
        $exception = new RuntimeException('Unexpected database error');

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        Log::shouldReceive('info')->once();

        Log::shouldReceive('critical')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => \str_contains($message, 'Unexpected exception')
                    && $context['exception'] === RuntimeException::class);

        $job = $this->createJobMock();
        $job->shouldReceive('fail')
            ->once()
            ->with($exception);
        $job->shouldReceive('attempts')->andReturn(1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected database error');

        $job->handle($this->mockUseCase);
    }

    #[Test]
    public function it_does_not_retry_on_unexpected_exception(): void
    {
        $exception = new RuntimeException('Unknown error');

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        Log::shouldReceive('info')->once();
        Log::shouldReceive('critical')->once();

        $job = $this->createJobMock();
        $job->shouldReceive('fail')
            ->once()
            ->with($exception);
        $job->shouldNotReceive('release'); // Should NOT release for retry
        $job->shouldReceive('attempts')->andReturn(2);

        $this->expectException(RuntimeException::class);

        $job->handle($this->mockUseCase);
    }

    /*
    |--------------------------------------------------------------------------
    | Failed Callback Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_logs_error_on_failed_callback(): void
    {
        $exception = new RuntimeException('Something went terribly wrong');

        Log::shouldReceive('error')
            ->once()
            ->with('Bing Ads to Mixpanel sync job failed', [
                'from' => self::TEST_DATE,
                'to' => self::TEST_DATE,
                'exception' => RuntimeException::class,
                'message' => 'Something went terribly wrong',
                'attempts' => 5,
            ]);

        $job = $this->createJobMock();
        $job->shouldReceive('attempts')->andReturn(5);

        $job->failed($exception);
    }

    #[Test]
    public function it_logs_external_service_exception_class_on_failure(): void
    {
        $exception = new ExternalServiceUnavailableException('Bing Ads');

        Log::shouldReceive('error')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => $context['exception'] === ExternalServiceUnavailableException::class
                    && $context['message'] === "External service 'Bing Ads' is unavailable");

        $job = $this->createJobMock();
        $job->shouldReceive('attempts')->andReturn(5);

        $job->failed($exception);
    }

    #[Test]
    public function it_logs_authentication_exception_class_on_failure(): void
    {
        $exception = new AuthenticationExpiredException('Bing Ads');

        Log::shouldReceive('error')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => $context['exception'] === AuthenticationExpiredException::class);

        $job = $this->createJobMock();
        $job->shouldReceive('attempts')->andReturn(3);

        $job->failed($exception);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    private function createJob(): SyncBingAdsToMixpanelJob
    {
        return new SyncBingAdsToMixpanelJob(
            new DateTimeImmutable(self::TEST_DATE),
            new DateTimeImmutable(self::TEST_DATE),
        );
    }

    /**
     * Create a partial mock that allows onQueue() from the constructor.
     *
     * Needed because JobMustCallOnQueueRule requires $this->onQueue() in constructors,
     * and Mockery partial mocks intercept the call without forwarding it.
     */
    private function createJobMock(string $from = self::TEST_DATE, string $to = self::TEST_DATE): SyncBingAdsToMixpanelJob&MockInterface
    {
        $job = Mockery::mock(SyncBingAdsToMixpanelJob::class)->makePartial();
        $job->allows('onQueue');
        $job->__construct(
            new DateTimeImmutable($from),
            new DateTimeImmutable($to),
        );

        return $job;
    }
}
