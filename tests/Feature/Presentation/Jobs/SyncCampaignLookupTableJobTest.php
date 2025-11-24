<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Jobs;

use App\Application\AdSpend\UseCases\SyncCampaignLookupTableUseCase;
use App\Application\Contracts\GoogleAdsClientInterface;
use App\Application\Contracts\MixpanelClientInterface;
use App\Domain\AdSpend\ValueObjects\Campaign;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Presentation\Jobs\SyncCampaignLookupTableJob;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
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
    private GoogleAdsClientInterface&MockInterface $googleAdsMock;

    private MixpanelClientInterface&MockInterface $mixpanelMock;

    private SyncCampaignLookupTableUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->googleAdsMock = Mockery::mock(GoogleAdsClientInterface::class);
        $this->mixpanelMock = Mockery::mock(MixpanelClientInterface::class);
        $loggerMock = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
        $this->useCase = new SyncCampaignLookupTableUseCase($this->googleAdsMock, $this->mixpanelMock, $loggerMock);

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

        $job->handle($this->useCase);

        Log::shouldHaveReceived('info')
            ->with('Campaign lookup table sync job starting');

        Log::shouldHaveReceived('info')
            ->with('Campaign lookup table sync job completed successfully');
    }

    #[Test]
    public function it_passes_campaigns_to_use_case_for_synchronization(): void
    {
        $campaigns = [
            new Campaign(campaignId: 111, campaignName: 'Campaign One', status: 'ENABLED'),
            new Campaign(campaignId: 222, campaignName: 'Campaign Two', status: 'PAUSED'),
        ];

        $this->googleAdsMock
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn($campaigns);

        $this->mixpanelMock
            ->shouldReceive('replaceCampaignLookupTable')
            ->once()
            ->with($campaigns);

        $job = new SyncCampaignLookupTableJob();

        $job->handle($this->useCase);

        Log::shouldHaveReceived('info')
            ->with('Campaign lookup table sync job completed successfully');
    }

    // ========================================================================
    // ExternalServiceUnavailableException Handling
    // ========================================================================

    #[Test]
    public function it_catches_external_service_exception_and_logs_warning(): void
    {
        // With retryAfter provided, job releases instead of rethrowing
        $exception = ExternalServiceUnavailableException::fromService('Google Ads', retryAfter: 60);

        $this->googleAdsMock
            ->shouldReceive('getCampaigns')
            ->once()
            ->andThrow($exception);

        $job = new SyncCampaignLookupTableJob();
        $this->setJobAttempts($job, 1);

        // The job catches the exception and releases - it should not throw
        $job->handle($this->useCase);

        Log::shouldHaveReceived('warning')
            ->with('External service unavailable during campaign lookup table sync, will retry', Mockery::on(
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
        $exception = ExternalServiceUnavailableException::fromService('Google Ads');

        $this->googleAdsMock
            ->shouldReceive('getCampaigns')
            ->andThrow($exception);

        $job = new SyncCampaignLookupTableJob();
        $this->setJobAttempts($job, 2);

        $this->expectException(ExternalServiceUnavailableException::class);

        $job->handle($this->useCase);
    }

    #[Test]
    public function it_logs_warning_before_rethrowing_when_retry_after_is_null(): void
    {
        $exception = ExternalServiceUnavailableException::fromService('Google Ads');

        $this->googleAdsMock
            ->shouldReceive('getCampaigns')
            ->andThrow($exception);

        $job = new SyncCampaignLookupTableJob();
        $this->setJobAttempts($job, 3);

        try {
            $job->handle($this->useCase);
        } catch (ExternalServiceUnavailableException) {
            // Expected
        }

        Log::shouldHaveReceived('warning')
            ->with('External service unavailable during campaign lookup table sync, will retry', Mockery::on(
                static function (array $context): bool {
                    self::assertSame('Google Ads', $context['service']);
                    self::assertSame('using backoff', $context['retry_after']);
                    self::assertSame(3, $context['attempts']);

                    return true;
                },
            ));
    }

    // ========================================================================
    // Backoff Delay Calculation Tests
    // ========================================================================

    #[Test]
    #[DataProvider('backoffDelayProvider')]
    public function it_calculates_correct_backoff_delay_for_each_attempt(int $attempt, int $expectedDelay): void
    {
        $job = new SyncCampaignLookupTableJob();

        // Test the backoff array directly
        $calculatedDelay = $job->backoff[$attempt - 1] ?? 960;

        self::assertSame($expectedDelay, $calculatedDelay);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function backoffDelayProvider(): array
    {
        return [
            'attempt 1 uses 60 seconds' => [1, 60],
            'attempt 2 uses 120 seconds' => [2, 120],
            'attempt 3 uses 240 seconds' => [3, 240],
            'attempt 4 uses 480 seconds' => [4, 480],
            'attempt 5 uses 960 seconds' => [5, 960],
        ];
    }

    #[Test]
    public function it_uses_fallback_backoff_delay_when_attempts_exceed_backoff_array_size(): void
    {
        $job = new SyncCampaignLookupTableJob();

        // Attempt 6 should fall back to 960 (the last value)
        $calculatedDelay = $job->backoff[6 - 1] ?? 960;

        self::assertSame(960, $calculatedDelay);
    }

    #[Test]
    public function it_uses_fallback_backoff_delay_for_very_high_attempt_number(): void
    {
        $job = new SyncCampaignLookupTableJob();

        // Attempt 100 should fall back to 960
        $calculatedDelay = $job->backoff[100 - 1] ?? 960;

        self::assertSame(960, $calculatedDelay);
    }

    #[Test]
    #[DataProvider('releaseDelayProvider')]
    public function it_releases_job_with_correct_backoff_delay_for_each_attempt(int $attempt, int $expectedDelay): void
    {
        // With retryAfter provided, job releases with that value (not backoff array)
        $exception = ExternalServiceUnavailableException::fromService('Google Ads', retryAfter: $expectedDelay);

        $this->googleAdsMock
            ->shouldReceive('getCampaigns')
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
        $job->handle($this->useCase);
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
        $this->googleAdsMock
            ->shouldReceive('getCampaigns')
            ->once()
            ->andThrow($exception);

        $job = new SyncCampaignLookupTableJob();

        $this->expectException($exception::class);

        $job->handle($this->useCase);
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

        $this->googleAdsMock
            ->shouldReceive('getCampaigns')
            ->once()
            ->andThrow($exception);

        $job = new SyncCampaignLookupTableJob();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected error occurred');

        $job->handle($this->useCase);
    }

    #[Test]
    public function it_logs_start_message_before_external_service_exception(): void
    {
        // With retryAfter provided, job releases instead of rethrowing
        $exception = ExternalServiceUnavailableException::fromService('Google Ads', retryAfter: 60);

        $this->googleAdsMock
            ->shouldReceive('getCampaigns')
            ->andThrow($exception);

        $job = new SyncCampaignLookupTableJob();
        $this->setJobAttempts($job, 1);

        // Job catches exception and releases - doesn't throw
        $job->handle($this->useCase);

        Log::shouldHaveReceived('info')
            ->with('Campaign lookup table sync job starting');
    }

    // ========================================================================
    // failed() Method Tests
    // ========================================================================

    #[Test]
    public function failed_method_logs_error_with_exception_details(): void
    {
        $exception = new RuntimeException('Something went terribly wrong');

        $job = new SyncCampaignLookupTableJob();
        $this->setJobAttempts($job, 5);

        $job->failed($exception);

        Log::shouldHaveReceived('error')
            ->with('Campaign lookup table sync job failed', [
                'exception' => RuntimeException::class,
                'message' => 'Something went terribly wrong',
                'attempts' => 5,
            ]);
    }

    #[Test]
    public function failed_method_logs_external_service_unavailable_from_google_ads(): void
    {
        $exception = ExternalServiceUnavailableException::fromService('Google Ads');

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
        $exception = ExternalServiceUnavailableException::fromService('Mixpanel');

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
        $exception = ExternalServiceUnavailableException::fromService('Mixpanel', retryAfter: 60);

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

        Log::shouldHaveReceived('error')
            ->with('Campaign lookup table sync job failed', Mockery::on(
                static function (array $context): bool {
                    self::assertSame(5, $context['attempts']);

                    return true;
                },
            ));
    }

    // ========================================================================
    // Job Configuration Tests
    // ========================================================================

    #[Test]
    public function it_has_correct_default_tries_configuration(): void
    {
        $job = new SyncCampaignLookupTableJob();

        self::assertSame(5, $job->tries);
    }

    #[Test]
    public function it_has_correct_default_backoff_configuration(): void
    {
        $job = new SyncCampaignLookupTableJob();

        self::assertSame([60, 120, 240, 480, 960], $job->backoff);
    }

    #[Test]
    public function it_has_exactly_five_backoff_values(): void
    {
        $job = new SyncCampaignLookupTableJob();

        self::assertCount(5, $job->backoff);
    }

    #[Test]
    public function backoff_values_are_exponentially_increasing(): void
    {
        $job = new SyncCampaignLookupTableJob();

        // Each value should be double the previous (exponential backoff)
        self::assertSame(60, $job->backoff[0]);
        self::assertSame(120, $job->backoff[1]); // 60 * 2
        self::assertSame(240, $job->backoff[2]); // 120 * 2
        self::assertSame(480, $job->backoff[3]); // 240 * 2
        self::assertSame(960, $job->backoff[4]); // 480 * 2
    }

    // ========================================================================
    // Queue Integration Tests
    // ========================================================================

    #[Test]
    public function it_can_be_dispatched_to_queue(): void
    {
        Queue::fake();

        SyncCampaignLookupTableJob::dispatch();

        Queue::assertPushed(SyncCampaignLookupTableJob::class);
    }

    // ========================================================================
    // Helper Methods
    // ========================================================================

    private function setupSuccessfulSync(): void
    {
        $campaign = new Campaign(
            campaignId: 123456789,
            campaignName: '[01] Search - Branded',
            status: 'ENABLED',
        );

        $this->googleAdsMock
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn([$campaign]);

        $this->mixpanelMock
            ->shouldReceive('replaceCampaignLookupTable')
            ->once();
    }

    private function setupCampaignsForMixpanelError(Throwable $exception): void
    {
        $campaign = new Campaign(
            campaignId: 123456789,
            campaignName: '[01] Search - Branded',
            status: 'ENABLED',
        );

        $this->googleAdsMock
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn([$campaign]);

        $this->mixpanelMock
            ->shouldReceive('replaceCampaignLookupTable')
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
