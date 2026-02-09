<?php

declare(strict_types=1);

namespace Tests\Feature\Application\Jobs\Mixpanel;

use App\Application\Contracts\LookupTableProviderInterface;
use App\Application\Contracts\MixpanelClientInterface;
use App\Application\Jobs\Mixpanel\SyncCampaignLookupTableJob;
use App\Application\Mixpanel\UseCases\SyncLookupTableUseCase;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;
use Throwable;

#[CoversClass(SyncCampaignLookupTableJob::class)]
final class SyncCampaignLookupTableJobTest extends TestCase
{
    private LookupTableProviderInterface&MockInterface $providerMock;

    private MixpanelClientInterface&MockInterface $mixpanelMock;

    private SyncLookupTableUseCase $useCase;

    private LoggerInterface&MockInterface $jobLoggerMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->providerMock = Mockery::mock(LookupTableProviderInterface::class);
        $this->mixpanelMock = Mockery::mock(MixpanelClientInterface::class);
        $loggerMock = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
        $this->useCase = new SyncLookupTableUseCase($this->providerMock, $this->mixpanelMock, $loggerMock);
        $this->jobLoggerMock = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

        // Setup default provider metadata
        $this->providerMock->shouldReceive('getTableKey')->andReturn('utm_campaigns')->byDefault();
        $this->providerMock->shouldReceive('getSourceName')->andReturn('Google Ads')->byDefault();
        $this->providerMock->shouldReceive('getHeaders')->andReturn(['utm_campaign', 'campaign_name', 'campaign_status'])->byDefault();

        Log::spy();
    }

    // ========================================================================
    // Happy Path Tests
    // ========================================================================

    #[Test]
    public function it_executes_successfully_and_logs_start_and_completion_messages(): void
    {
        $this->setupSuccessfulSync();

        $job = new SyncCampaignLookupTableJob();

        $job->handle($this->useCase, $this->jobLoggerMock);

        $this->jobLoggerMock->shouldHaveReceived('info')
            ->with('Campaign lookup table sync job starting');

        $this->jobLoggerMock->shouldHaveReceived('info')
            ->with('Campaign lookup table sync job completed successfully');
    }

    #[Test]
    public function it_passes_rows_to_use_case_for_synchronization(): void
    {
        $rows = [
            ['111', 'Campaign One', 'ENABLED'],
            ['222', 'Campaign Two', 'PAUSED'],
        ];

        $this->providerMock
            ->shouldReceive('fetchRows')
            ->once()
            ->andReturn($rows);

        $this->mixpanelMock
            ->shouldReceive('replaceLookupTable')
            ->once()
            ->with('utm_campaigns', ['utm_campaign', 'campaign_name', 'campaign_status'], $rows);

        $job = new SyncCampaignLookupTableJob();

        $job->handle($this->useCase, $this->jobLoggerMock);

        $this->jobLoggerMock->shouldHaveReceived('info')
            ->with('Campaign lookup table sync job completed successfully');
    }

    // ========================================================================
    // ExternalServiceUnavailableException Handling
    // ========================================================================

    #[Test]
    public function it_catches_external_service_exception_and_logs_warning(): void
    {
        // With retryAfter provided, job releases instead of rethrowing
        $exception = new ExternalServiceUnavailableException('Google Ads', retryAfter: 60);

        $this->providerMock
            ->shouldReceive('fetchRows')
            ->once()
            ->andThrow($exception);

        $job = new SyncCampaignLookupTableJob();
        $this->setJobAttempts($job, 1);

        // The job catches the exception and releases - it should not throw
        $job->handle($this->useCase, $this->jobLoggerMock);

        $this->jobLoggerMock->shouldHaveReceived('warning')
            ->with('Campaign lookup table sync service unavailable, will retry', Mockery::on(
                static function (array $context): bool {
                    self::assertSame('Google Ads', $context['service']);
                    self::assertSame(60, $context['retry_after']);
                    self::assertSame(1, $context['attempts']);

                    return true;
                },
            ));
    }

    #[Test]
    public function it_rethrows_exception_when_retry_after_is_null(): void
    {
        // Without retryAfter, exception is rethrown for Laravel to handle backoff
        $exception = new ExternalServiceUnavailableException('Google Ads');

        $this->providerMock
            ->shouldReceive('fetchRows')
            ->andThrow($exception);

        $job = new SyncCampaignLookupTableJob();
        $this->setJobAttempts($job, 2);

        $this->expectException(ExternalServiceUnavailableException::class);

        $job->handle($this->useCase, $this->jobLoggerMock);
    }

    #[Test]
    public function it_logs_warning_before_rethrowing_when_retry_after_is_null(): void
    {
        $exception = new ExternalServiceUnavailableException('Google Ads');

        $this->providerMock
            ->shouldReceive('fetchRows')
            ->andThrow($exception);

        $job = new SyncCampaignLookupTableJob();
        $this->setJobAttempts($job, 3);

        try {
            $job->handle($this->useCase, $this->jobLoggerMock);
        } catch (ExternalServiceUnavailableException) {
            // Expected
        }

        $this->jobLoggerMock->shouldHaveReceived('warning')
            ->with('Campaign lookup table sync service unavailable, will retry', Mockery::on(
                static function (array $context): bool {
                    self::assertSame('Google Ads', $context['service']);
                    self::assertNull($context['retry_after']);
                    self::assertSame(3, $context['attempts']);

                    return true;
                },
            ));
    }

    // ========================================================================
    // UnexpectedApiResultException Handling (Permanent Failures)
    // ========================================================================

    #[Test]
    public function it_catches_unexpected_api_result_exception_and_fails_immediately(): void
    {
        // Permanent failure - retrying won't help
        $exception = new UnexpectedApiResultException(
            serviceName: 'Google Ads',
            reason: 'Campaign data missing required fields',
        );

        $this->providerMock
            ->shouldReceive('fetchRows')
            ->once()
            ->andThrow($exception);

        $job = new SyncCampaignLookupTableJob();

        // Mock the queue job to verify fail is called
        $queueJob = Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldReceive('fail')->once()->with($exception);
        $queueJob->shouldReceive('isReleased')->andReturn(false);
        $queueJob->shouldReceive('isDeletedOrReleased')->andReturn(false);
        $job->setJob($queueJob);

        try {
            $job->handle($this->useCase, $this->jobLoggerMock);
            self::fail('Expected UnexpectedApiResultException was not thrown');
        } catch (UnexpectedApiResultException) {
            // Expected - jobs now rethrow after fail()
        }
    }

    #[Test]
    public function it_rethrows_unexpected_api_result_exception_after_failing(): void
    {
        $exception = new UnexpectedApiResultException(
            serviceName: 'Mixpanel',
            reason: 'Lookup table response malformed',
        );

        $this->providerMock
            ->shouldReceive('fetchRows')
            ->once()
            ->andThrow($exception);

        $job = new SyncCampaignLookupTableJob();

        // Mock the queue job
        $queueJob = Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('attempts')->andReturn(2);
        $queueJob->shouldReceive('fail')->once();
        $queueJob->shouldReceive('isReleased')->andReturn(false);
        $queueJob->shouldReceive('isDeletedOrReleased')->andReturn(false);
        $job->setJob($queueJob);

        $this->expectException(UnexpectedApiResultException::class);

        $job->handle($this->useCase, $this->jobLoggerMock);
    }

    // ========================================================================
    // AuthenticationExpiredException Handling
    // ========================================================================

    #[Test]
    public function it_fails_immediately_on_authentication_expired_exception(): void
    {
        $exception = new AuthenticationExpiredException('Google Ads');

        $this->providerMock
            ->shouldReceive('fetchRows')
            ->once()
            ->andThrow($exception);

        $job = new SyncCampaignLookupTableJob();

        $queueJob = Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldReceive('fail')->once()->with($exception)->andReturnNull();
        $queueJob->shouldReceive('isReleased')->andReturn(false);
        $queueJob->shouldReceive('isDeletedOrReleased')->andReturn(false);
        $job->setJob($queueJob);

        try {
            $job->handle($this->useCase, $this->jobLoggerMock);
            self::fail('Expected AuthenticationExpiredException was not thrown');
        } catch (AuthenticationExpiredException) {
            // Expected - jobs now rethrow after fail()
        }
    }

    #[Test]
    public function it_does_not_retry_on_authentication_expired_exception(): void
    {
        $exception = new AuthenticationExpiredException('Mixpanel');

        $this->setupSuccessfulFetchForMixpanelError($exception);

        $job = new SyncCampaignLookupTableJob();

        $queueJob = Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('attempts')->andReturn(2);
        $queueJob->shouldReceive('fail')->once()->with($exception)->andReturnNull();
        $queueJob->shouldReceive('release')->never(); // Should NOT release for retry
        $queueJob->shouldReceive('isReleased')->andReturn(false);
        $queueJob->shouldReceive('isDeletedOrReleased')->andReturn(false);
        $job->setJob($queueJob);

        try {
            $job->handle($this->useCase, $this->jobLoggerMock);
            self::fail('Expected AuthenticationExpiredException was not thrown');
        } catch (AuthenticationExpiredException) {
            // Expected - jobs now rethrow after fail()
        }
    }

    // ========================================================================
    // Release Behavior Tests
    // ========================================================================

    #[Test]
    #[DataProvider('releaseDelayProvider')]
    public function it_releases_job_with_correct_backoff_delay_for_each_attempt(int $attempt, int $expectedDelay): void
    {
        // With retryAfter provided, job releases with that value (not backoff array)
        $exception = new ExternalServiceUnavailableException('Google Ads', retryAfter: $expectedDelay);

        $this->providerMock
            ->shouldReceive('fetchRows')
            ->andThrow($exception);

        $job = new SyncCampaignLookupTableJob();

        // Mock the queue job to verify release is called with exact delay
        $queueJob = Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('attempts')->andReturn($attempt);
        $queueJob->shouldReceive('release')->once()->with($expectedDelay)->andReturnNull();
        $queueJob->shouldReceive('isReleased')->andReturn(false);
        $queueJob->shouldReceive('isDeletedOrReleased')->andReturn(false);
        $job->setJob($queueJob);

        // Should not throw - catches ExternalServiceUnavailableException
        $job->handle($this->useCase, $this->jobLoggerMock);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function releaseDelayProvider(): array
    {
        return [
            'attempt 1 releases with 60 seconds' => [1, 60],
            'attempt 2 releases with 120 seconds' => [2, 120],
            'attempt 3 releases with 240 seconds' => [3, 240],
            'attempt 4 releases with 480 seconds' => [4, 480],
            'attempt 5 releases with 960 seconds' => [5, 960],
            'attempt 6 releases with 960 fallback' => [6, 960],
        ];
    }

    // ========================================================================
    // Exception Propagation Tests
    // ========================================================================

    #[Test]
    #[DataProvider('propagatedExceptionProvider')]
    public function it_allows_non_rate_limit_api_exceptions_to_propagate(Throwable $exception): void
    {
        $this->providerMock
            ->shouldReceive('fetchRows')
            ->once()
            ->andThrow($exception);

        $job = new SyncCampaignLookupTableJob();

        $this->expectException($exception::class);

        $job->handle($this->useCase, $this->jobLoggerMock);
    }

    /**
     * @return array<string, array{Throwable}>
     */
    public static function propagatedExceptionProvider(): array
    {
        return [
            'RuntimeException propagates' => [
                new RuntimeException('Unexpected error'),
            ],
        ];
    }

    #[Test]
    public function it_does_not_catch_generic_runtime_exception(): void
    {
        $exception = new RuntimeException('Unexpected error occurred');

        $this->providerMock
            ->shouldReceive('fetchRows')
            ->once()
            ->andThrow($exception);

        $job = new SyncCampaignLookupTableJob();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected error occurred');

        $job->handle($this->useCase, $this->jobLoggerMock);
    }

    #[Test]
    public function it_logs_start_message_before_external_service_exception(): void
    {
        // With retryAfter provided, job releases instead of rethrowing
        $exception = new ExternalServiceUnavailableException('Google Ads', retryAfter: 60);

        $this->providerMock
            ->shouldReceive('fetchRows')
            ->andThrow($exception);

        $job = new SyncCampaignLookupTableJob();
        $this->setJobAttempts($job, 1);

        // Job catches exception and releases - doesn't throw
        $job->handle($this->useCase, $this->jobLoggerMock);

        $this->jobLoggerMock->shouldHaveReceived('info')
            ->with('Campaign lookup table sync job starting');
    }

    // ========================================================================
    // failed() Method Tests
    // ========================================================================

    #[Test]
    public function failed_method_logs_critical_with_exception_details(): void
    {
        $exception = new RuntimeException('Something went terribly wrong');

        $job = new SyncCampaignLookupTableJob();
        $this->setJobAttempts($job, 5);

        $job->failed($exception);

        Log::shouldHaveReceived('critical')
            ->with('Campaign lookup table sync job failed', [
                'exception' => RuntimeException::class,
                'message' => 'Something went terribly wrong',
                'attempts' => 5,
            ]);
    }

    #[Test]
    public function failed_method_logs_external_service_unavailable_from_google_ads(): void
    {
        $exception = new ExternalServiceUnavailableException('Google Ads');

        $job = new SyncCampaignLookupTableJob();
        $this->setJobAttempts($job, 3);

        $job->failed($exception);

        Log::shouldHaveReceived('error')
            ->with('Campaign lookup table sync job failed', [
                'exception' => ExternalServiceUnavailableException::class,
                'message' => "External service 'Google Ads' is unavailable",
                'attempts' => 3,
            ]);
    }

    #[Test]
    public function failed_method_logs_external_service_unavailable_from_mixpanel(): void
    {
        $exception = new ExternalServiceUnavailableException('Mixpanel');

        $job = new SyncCampaignLookupTableJob();
        $this->setJobAttempts($job, 2);

        $job->failed($exception);

        Log::shouldHaveReceived('error')
            ->with('Campaign lookup table sync job failed', [
                'exception' => ExternalServiceUnavailableException::class,
                'message' => "External service 'Mixpanel' is unavailable",
                'attempts' => 2,
            ]);
    }

    #[Test]
    public function failed_method_logs_external_service_unavailable_rate_limit(): void
    {
        $exception = new ExternalServiceUnavailableException('Mixpanel', retryAfter: 60);

        $job = new SyncCampaignLookupTableJob();
        $this->setJobAttempts($job, 5);

        $job->failed($exception);

        Log::shouldHaveReceived('error')
            ->with('Campaign lookup table sync job failed', [
                'exception' => ExternalServiceUnavailableException::class,
                'message' => "External service 'Mixpanel' is unavailable",
                'attempts' => 5,
            ]);
    }

    #[Test]
    public function failed_method_logs_attempt_count_on_final_attempt(): void
    {
        $exception = new RuntimeException('Final attempt failure');

        $job = new SyncCampaignLookupTableJob();
        $this->setJobAttempts($job, 5);

        $job->failed($exception);

        Log::shouldHaveReceived('critical')
            ->with('Campaign lookup table sync job failed', Mockery::on(
                static function (array $context): bool {
                    self::assertSame(5, $context['attempts']);

                    return true;
                },
            ));
    }

    // ========================================================================
    // Helper Methods
    // ========================================================================

    private function setupSuccessfulSync(): void
    {
        $rows = [
            ['123456789', '[01] Search - Branded', 'ENABLED'],
        ];

        $this->providerMock
            ->shouldReceive('fetchRows')
            ->once()
            ->andReturn($rows);

        $this->mixpanelMock
            ->shouldReceive('replaceLookupTable')
            ->once();
    }

    /**
     * Setup provider to return rows, then Mixpanel throws exception.
     */
    private function setupSuccessfulFetchForMixpanelError(Throwable $exception): void
    {
        $rows = [
            ['123456789', '[01] Search - Branded', 'ENABLED'],
        ];

        $this->providerMock
            ->shouldReceive('fetchRows')
            ->once()
            ->andReturn($rows);

        $this->mixpanelMock
            ->shouldReceive('replaceLookupTable')
            ->once()
            ->andThrow($exception);
    }

    /**
     * Set the job's underlying queue job to mock attempts() and handle release.
     */
    private function setJobAttempts(SyncCampaignLookupTableJob $job, int $attempts): void
    {
        $queueJob = Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('attempts')->andReturn($attempts);
        $queueJob->shouldReceive('release')->with(Mockery::any())->andReturnNull();
        $queueJob->shouldReceive('isReleased')->andReturn(false);
        $queueJob->shouldReceive('isDeletedOrReleased')->andReturn(false);

        $job->setJob($queueJob);
    }
}
