<?php

declare(strict_types=1);

namespace Tests\Feature\Application\Jobs\Mixpanel;

use App\Application\AdSpend\UseCases\SyncAdSpendUseCase;
use App\Application\Contracts\AdSpendClientInterface;
use App\Application\Contracts\MixpanelClientInterface;
use App\Application\Jobs\Mixpanel\SyncGoogleAdsToMixpanelJob;
use App\Domain\AdSpend\Enums\AdSource;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\PayloadSerializationException;
use App\Domain\ValueObjects\DateRange;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;
use Throwable;

#[CoversClass(SyncGoogleAdsToMixpanelJob::class)]
final class SyncGoogleAdsToMixpanelJobTest extends TestCase
{
    private AdSpendClientInterface&MockInterface $adClientMock;

    private MixpanelClientInterface&MockInterface $mixpanelMock;

    private SyncAdSpendUseCase $useCase;

    private LoggerInterface&MockInterface $jobLoggerMock;

    private const string TEST_DATE = '2024-11-20';

    protected function setUp(): void
    {
        parent::setUp();

        $this->adClientMock = Mockery::mock(AdSpendClientInterface::class);
        $this->adClientMock->shouldReceive('getSource')->andReturn(AdSource::Google)->byDefault();
        $this->mixpanelMock = Mockery::mock(MixpanelClientInterface::class);
        $loggerMock = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
        $this->useCase = new SyncAdSpendUseCase($this->adClientMock, $this->mixpanelMock, $loggerMock);
        $this->jobLoggerMock = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

        Log::spy();
    }

    // ========================================================================
    // Happy Path Tests
    // ========================================================================

    #[Test]
    public function it_executes_successfully_and_logs_start_and_completion_messages(): void
    {
        $this->setupSuccessfulSync(self::TEST_DATE);

        $job = new SyncGoogleAdsToMixpanelJob(new DateTimeImmutable(self::TEST_DATE), new DateTimeImmutable(self::TEST_DATE));

        $job->handle($this->useCase, $this->jobLoggerMock);

        $this->jobLoggerMock->shouldHaveReceived('info')
            ->with('Queued Google Ads to Mixpanel sync starting', ['from' => self::TEST_DATE, 'to' => self::TEST_DATE]);

        $this->jobLoggerMock->shouldHaveReceived('info')
            ->with('Queued Google Ads to Mixpanel sync completed', ['from' => self::TEST_DATE, 'to' => self::TEST_DATE]);
    }

    #[Test]
    public function it_passes_correct_date_range_to_use_case(): void
    {
        $specificDate = '2024-12-31';

        $this->adClientMock
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->once()
            ->with(Mockery::on(static fn(DateRange $range): bool => $range->from->format('Y-m-d') === $specificDate && $range->to->format('Y-m-d') === $specificDate))
            ->andReturn([]);

        $job = new SyncGoogleAdsToMixpanelJob(new DateTimeImmutable($specificDate), new DateTimeImmutable($specificDate));

        $job->handle($this->useCase, $this->jobLoggerMock);

        $this->jobLoggerMock->shouldHaveReceived('info')
            ->with('Queued Google Ads to Mixpanel sync starting', ['from' => $specificDate, 'to' => $specificDate]);
    }

    // ========================================================================
    // ExternalServiceUnavailableException Handling
    // ========================================================================

    #[Test]
    public function it_catches_external_service_exception_and_logs_warning(): void
    {
        // With retryAfter provided, job releases instead of rethrowing
        $exception = new ExternalServiceUnavailableException('Google Ads', retryAfter: 60);

        $this->adClientMock
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->once()
            ->with(Mockery::on(static fn(DateRange $range): bool => $range->from->format('Y-m-d') === self::TEST_DATE && $range->to->format('Y-m-d') === self::TEST_DATE))
            ->andThrow($exception);

        $job = new SyncGoogleAdsToMixpanelJob(new DateTimeImmutable(self::TEST_DATE), new DateTimeImmutable(self::TEST_DATE));
        $this->setJobAttempts($job, 1);

        // The job catches the exception and releases - it should not throw
        $job->handle($this->useCase, $this->jobLoggerMock);

        $this->jobLoggerMock->shouldHaveReceived('warning')
            ->with('Google Ads sync service unavailable, will retry', Mockery::on(
                static function (array $context): bool {
                    self::assertSame(self::TEST_DATE, $context['from']);
                    self::assertSame(self::TEST_DATE, $context['to']);
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

        $this->adClientMock
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->andThrow($exception);

        $job = new SyncGoogleAdsToMixpanelJob(new DateTimeImmutable(self::TEST_DATE), new DateTimeImmutable(self::TEST_DATE));
        $this->setJobAttempts($job, 2);

        $this->expectException(ExternalServiceUnavailableException::class);

        $job->handle($this->useCase, $this->jobLoggerMock);
    }

    #[Test]
    public function it_logs_warning_before_rethrowing_when_retry_after_is_null(): void
    {
        $exception = new ExternalServiceUnavailableException('Google Ads');

        $this->adClientMock
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->andThrow($exception);

        $job = new SyncGoogleAdsToMixpanelJob(new DateTimeImmutable(self::TEST_DATE), new DateTimeImmutable(self::TEST_DATE));
        $this->setJobAttempts($job, 3);

        try {
            $job->handle($this->useCase, $this->jobLoggerMock);
        } catch (ExternalServiceUnavailableException) {
            // Expected
        }

        $this->jobLoggerMock->shouldHaveReceived('warning')
            ->with('Google Ads sync service unavailable, will retry', Mockery::on(
                static function (array $context): bool {
                    self::assertSame(self::TEST_DATE, $context['from']);
                    self::assertSame(self::TEST_DATE, $context['to']);
                    self::assertSame('Google Ads', $context['service']);
                    self::assertNull($context['retry_after']);
                    self::assertSame(3, $context['attempts']);

                    return true;
                },
            ));
    }

    // ========================================================================
    // Release Behavior Tests
    // ========================================================================

    #[Test]
    public function it_releases_job_with_api_provided_retry_after(): void
    {
        // When API provides retryAfter, job releases with that exact value
        $exception = new ExternalServiceUnavailableException('Google Ads', retryAfter: 180);

        $this->adClientMock
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->andThrow($exception);

        $job = new SyncGoogleAdsToMixpanelJob(new DateTimeImmutable(self::TEST_DATE), new DateTimeImmutable(self::TEST_DATE));

        $queueJob = Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldReceive('release')->once()->with(180)->andReturnNull();
        $queueJob->shouldReceive('isReleased')->andReturn(false);
        $queueJob->shouldReceive('isDeletedOrReleased')->andReturn(false);
        $job->setJob($queueJob);

        $job->handle($this->useCase, $this->jobLoggerMock);
    }

    #[Test]
    #[DataProvider('releaseDelayProvider')]
    public function it_releases_job_with_correct_backoff_delay_for_each_attempt(int $attempt, int $expectedDelay): void
    {
        // With retryAfter provided, job releases with that value (not backoff array)
        $exception = new ExternalServiceUnavailableException('Google Ads', retryAfter: $expectedDelay);

        $this->adClientMock
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->andThrow($exception);

        $job = new SyncGoogleAdsToMixpanelJob(new DateTimeImmutable(self::TEST_DATE), new DateTimeImmutable(self::TEST_DATE));

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
        $this->adClientMock
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->once()
            ->with(Mockery::on(static fn(DateRange $range): bool => $range->from->format('Y-m-d') === self::TEST_DATE && $range->to->format('Y-m-d') === self::TEST_DATE))
            ->andThrow($exception);

        $job = new SyncGoogleAdsToMixpanelJob(new DateTimeImmutable(self::TEST_DATE), new DateTimeImmutable(self::TEST_DATE));

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
    public function it_propagates_runtime_exception_with_correct_message(): void
    {
        $exception = new RuntimeException('Unexpected error occurred');

        $this->adClientMock
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->once()
            ->andThrow($exception);

        $job = new SyncGoogleAdsToMixpanelJob(new DateTimeImmutable(self::TEST_DATE), new DateTimeImmutable(self::TEST_DATE));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected error occurred');

        $job->handle($this->useCase, $this->jobLoggerMock);
    }

    #[Test]
    public function it_catches_external_service_exception_from_mixpanel_client(): void
    {
        // With retryAfter provided, job releases instead of rethrowing
        $exception = new ExternalServiceUnavailableException('Mixpanel', retryAfter: 60);

        $this->setupCampaignsForMixpanelError($exception);

        $job = new SyncGoogleAdsToMixpanelJob(new DateTimeImmutable(self::TEST_DATE), new DateTimeImmutable(self::TEST_DATE));
        $this->setJobAttempts($job, 1);

        // The job catches the exception and releases - it should not throw
        $job->handle($this->useCase, $this->jobLoggerMock);

        $this->jobLoggerMock->shouldHaveReceived('warning')
            ->with('Google Ads sync service unavailable, will retry', Mockery::on(
                static function (array $context): bool {
                    self::assertSame(self::TEST_DATE, $context['from']);
                    self::assertSame(self::TEST_DATE, $context['to']);
                    self::assertSame('Mixpanel', $context['service']);
                    self::assertSame(60, $context['retry_after']);
                    self::assertSame(1, $context['attempts']);

                    return true;
                },
            ));
    }

    #[Test]
    public function it_does_not_catch_generic_runtime_exception(): void
    {
        $exception = new RuntimeException('Unexpected error occurred');

        $this->adClientMock
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->once()
            ->andThrow($exception);

        $job = new SyncGoogleAdsToMixpanelJob(new DateTimeImmutable(self::TEST_DATE), new DateTimeImmutable(self::TEST_DATE));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected error occurred');

        $job->handle($this->useCase, $this->jobLoggerMock);
    }

    #[Test]
    public function it_logs_start_message_before_exception_propagates(): void
    {
        $exception = new RuntimeException('Test error');

        $this->adClientMock
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->andThrow($exception);

        $job = new SyncGoogleAdsToMixpanelJob(new DateTimeImmutable(self::TEST_DATE), new DateTimeImmutable(self::TEST_DATE));

        try {
            $job->handle($this->useCase, $this->jobLoggerMock);
        } catch (RuntimeException) {
            // Expected
        }

        $this->jobLoggerMock->shouldHaveReceived('info')
            ->with('Queued Google Ads to Mixpanel sync starting', ['from' => self::TEST_DATE, 'to' => self::TEST_DATE]);
    }

    // ========================================================================
    // failed() Method Tests
    // ========================================================================

    #[Test]
    public function failed_method_logs_critical_with_exception_details(): void
    {
        $exception = new RuntimeException('Something went terribly wrong');

        $job = new SyncGoogleAdsToMixpanelJob(new DateTimeImmutable(self::TEST_DATE), new DateTimeImmutable(self::TEST_DATE));
        $this->setJobAttempts($job, 5);

        $job->failed($exception);

        Log::shouldHaveReceived('critical')
            ->with('Google Ads to Mixpanel sync job failed', [
                'from' => self::TEST_DATE,
                'to' => self::TEST_DATE,
                'exception' => RuntimeException::class,
                'message' => 'Something went terribly wrong',
                'attempts' => 5,
            ]);
    }

    #[Test]
    public function failed_method_logs_external_service_exception_class(): void
    {
        $exception = new ExternalServiceUnavailableException('Google Ads');

        $job = new SyncGoogleAdsToMixpanelJob(new DateTimeImmutable(self::TEST_DATE), new DateTimeImmutable(self::TEST_DATE));
        $this->setJobAttempts($job, 3);

        $job->failed($exception);

        Log::shouldHaveReceived('error')
            ->with('Google Ads to Mixpanel sync job failed', [
                'from' => self::TEST_DATE,
                'to' => self::TEST_DATE,
                'exception' => ExternalServiceUnavailableException::class,
                'message' => "External service 'Google Ads' is unavailable",
                'attempts' => 3,
            ]);
    }

    #[Test]
    public function failed_method_logs_mixpanel_external_service_exception_class(): void
    {
        $exception = new ExternalServiceUnavailableException('Mixpanel');

        $job = new SyncGoogleAdsToMixpanelJob(new DateTimeImmutable(self::TEST_DATE), new DateTimeImmutable(self::TEST_DATE));
        $this->setJobAttempts($job, 2);

        $job->failed($exception);

        Log::shouldHaveReceived('error')
            ->with('Google Ads to Mixpanel sync job failed', [
                'from' => self::TEST_DATE,
                'to' => self::TEST_DATE,
                'exception' => ExternalServiceUnavailableException::class,
                'message' => "External service 'Mixpanel' is unavailable",
                'attempts' => 2,
            ]);
    }

    #[Test]
    public function failed_method_logs_external_service_exception_after_max_retries(): void
    {
        $exception = new ExternalServiceUnavailableException('Google Ads');

        $job = new SyncGoogleAdsToMixpanelJob(new DateTimeImmutable(self::TEST_DATE), new DateTimeImmutable(self::TEST_DATE));
        $this->setJobAttempts($job, 5);

        $job->failed($exception);

        Log::shouldHaveReceived('error')
            ->with('Google Ads to Mixpanel sync job failed', [
                'from' => self::TEST_DATE,
                'to' => self::TEST_DATE,
                'exception' => ExternalServiceUnavailableException::class,
                'message' => "External service 'Google Ads' is unavailable",
                'attempts' => 5,
            ]);
    }

    #[Test]
    public function failed_method_logs_attempt_count_on_first_attempt(): void
    {
        $exception = new RuntimeException('First attempt failure');

        $job = new SyncGoogleAdsToMixpanelJob(new DateTimeImmutable(self::TEST_DATE), new DateTimeImmutable(self::TEST_DATE));
        $this->setJobAttempts($job, 1);

        $job->failed($exception);

        Log::shouldHaveReceived('critical')
            ->with('Google Ads to Mixpanel sync job failed', Mockery::on(
                static function (array $context): bool {
                    self::assertSame(1, $context['attempts']);

                    return true;
                },
            ));
    }

    // ========================================================================
    // Constructor Tests
    // ========================================================================

    #[Test]
    public function it_stores_date_range_from_constructor(): void
    {
        $expectedDate = '2024-06-15';

        $this->adClientMock
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->once()
            ->with(Mockery::on(static fn(DateRange $range): bool => $range->from->format('Y-m-d') === $expectedDate && $range->to->format('Y-m-d') === $expectedDate))
            ->andReturn([]);

        $job = new SyncGoogleAdsToMixpanelJob(new DateTimeImmutable($expectedDate), new DateTimeImmutable($expectedDate));

        $job->handle($this->useCase, $this->jobLoggerMock);

        $this->jobLoggerMock->shouldHaveReceived('info')
            ->with('Queued Google Ads to Mixpanel sync starting', ['from' => $expectedDate, 'to' => $expectedDate]);
    }

    // ========================================================================
    // PayloadSerializationException Handling
    // ========================================================================

    #[Test]
    public function it_fails_immediately_on_payload_serialization_exception(): void
    {
        $exception = new PayloadSerializationException('Mixpanel', 'JSON encoding failed');

        $this->setupCampaignsForMixpanelError($exception);

        $job = new SyncGoogleAdsToMixpanelJob(new DateTimeImmutable(self::TEST_DATE), new DateTimeImmutable(self::TEST_DATE));

        $queueJob = Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldReceive('fail')->once()->with($exception)->andReturnNull();
        $queueJob->shouldReceive('isReleased')->andReturn(false);
        $queueJob->shouldReceive('isDeletedOrReleased')->andReturn(false);
        $job->setJob($queueJob);

        try {
            $job->handle($this->useCase, $this->jobLoggerMock);
            self::fail('Expected PayloadSerializationException was not thrown');
        } catch (PayloadSerializationException) {
            // Expected - jobs now rethrow after fail()
        }
    }

    #[Test]
    public function it_does_not_retry_on_payload_serialization_exception(): void
    {
        $exception = new PayloadSerializationException('Mixpanel', 'Invalid data structure');

        $this->setupCampaignsForMixpanelError($exception);

        $job = new SyncGoogleAdsToMixpanelJob(new DateTimeImmutable(self::TEST_DATE), new DateTimeImmutable(self::TEST_DATE));

        $queueJob = Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldReceive('fail')->once()->with($exception)->andReturnNull();
        $queueJob->shouldReceive('release')->never(); // Should NOT release for retry
        $queueJob->shouldReceive('isReleased')->andReturn(false);
        $queueJob->shouldReceive('isDeletedOrReleased')->andReturn(false);
        $job->setJob($queueJob);

        try {
            $job->handle($this->useCase, $this->jobLoggerMock);
            self::fail('Expected PayloadSerializationException was not thrown');
        } catch (PayloadSerializationException) {
            // Expected - jobs now rethrow after fail()
        }
    }

    // ========================================================================
    // AuthenticationExpiredException Handling
    // ========================================================================

    #[Test]
    public function it_fails_immediately_on_authentication_expired_exception(): void
    {
        $exception = new AuthenticationExpiredException('Google Ads');

        $this->adClientMock
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->once()
            ->andThrow($exception);

        $job = new SyncGoogleAdsToMixpanelJob(new DateTimeImmutable(self::TEST_DATE), new DateTimeImmutable(self::TEST_DATE));

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

        $this->setupCampaignsForMixpanelError($exception);

        $job = new SyncGoogleAdsToMixpanelJob(new DateTimeImmutable(self::TEST_DATE), new DateTimeImmutable(self::TEST_DATE));

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
    // Queue Integration Tests
    // ========================================================================

    #[Test]
    public function it_can_be_dispatched_to_queue(): void
    {
        Queue::fake();

        $testDate = new DateTimeImmutable(self::TEST_DATE);
        SyncGoogleAdsToMixpanelJob::dispatch($testDate, $testDate);

        Queue::assertPushed(SyncGoogleAdsToMixpanelJob::class, static function (SyncGoogleAdsToMixpanelJob $job) use ($testDate): bool {
            // Access private properties via reflection to verify date range
            $reflection = new ReflectionClass($job);

            $fromProperty = $reflection->getProperty('from');
            $toProperty = $reflection->getProperty('to');

            $jobFrom = $fromProperty->getValue($job);
            $jobTo = $toProperty->getValue($job);

            return $jobFrom instanceof DateTimeImmutable
                && $jobTo instanceof DateTimeImmutable
                && $jobFrom->format('Y-m-d') === $testDate->format('Y-m-d')
                && $jobTo->format('Y-m-d') === $testDate->format('Y-m-d');
        });
    }

    // ========================================================================
    // Helper Methods
    // ========================================================================

    private function setupSuccessfulSync(string $date): void
    {
        $campaign = new CampaignMetrics(
            campaignId: 123456,
            campaignName: 'Test Campaign',
            date: $date,
            costInPounds: 100.00,
            clicks: 100,
            impressions: 5000,
            conversions: 5.0,
        );

        $this->adClientMock
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->once()
            ->with(Mockery::on(static fn(DateRange $range): bool => $range->from->format('Y-m-d') === $date && $range->to->format('Y-m-d') === $date))
            ->andReturn([$campaign]);

        $this->mixpanelMock
            ->shouldReceive('importCampaigns')
            ->once();
    }

    private function setupCampaignsForMixpanelError(Throwable $exception): void
    {
        $campaign = new CampaignMetrics(
            campaignId: 123456,
            campaignName: 'Test Campaign',
            date: self::TEST_DATE,
            costInPounds: 100.00,
            clicks: 100,
            impressions: 5000,
            conversions: 5.0,
        );

        $this->adClientMock
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->once()
            ->andReturn([$campaign]);

        $this->mixpanelMock
            ->shouldReceive('importCampaigns')
            ->once()
            ->andThrow($exception);
    }

    /**
     * Set the job's underlying queue job to mock attempts() and handle release.
     */
    private function setJobAttempts(SyncGoogleAdsToMixpanelJob $job, int $attempts): void
    {
        $queueJob = Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('attempts')->andReturn($attempts);
        $queueJob->shouldReceive('release')->with(Mockery::any())->andReturnNull();
        $queueJob->shouldReceive('isReleased')->andReturn(false);
        $queueJob->shouldReceive('isDeletedOrReleased')->andReturn(false);

        $job->setJob($queueJob);
    }
}
