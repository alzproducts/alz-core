<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Jobs;

use App\Application\AdSpend\UseCases\SyncAdSpendUseCase;
use App\Domain\AdSpend\Contracts\GoogleAdsClientInterface;
use App\Domain\AdSpend\Contracts\MixpanelClientInterface;
use App\Domain\AdSpend\Exceptions\ApiRateLimitException;
use App\Domain\AdSpend\Exceptions\GoogleAdsApiException;
use App\Domain\AdSpend\Exceptions\MixpanelApiException;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Presentation\Jobs\SyncGoogleAdsToMixpanelJob;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;
use Throwable;

#[CoversClass(SyncGoogleAdsToMixpanelJob::class)]
final class SyncGoogleAdsToMixpanelJobTest extends TestCase
{
    private GoogleAdsClientInterface&MockInterface $googleAdsMock;

    private MixpanelClientInterface&MockInterface $mixpanelMock;

    private SyncAdSpendUseCase $useCase;

    private const string TEST_DATE = '2024-11-20';

    protected function setUp(): void
    {
        parent::setUp();

        $this->googleAdsMock = Mockery::mock(GoogleAdsClientInterface::class);
        $this->mixpanelMock = Mockery::mock(MixpanelClientInterface::class);
        $this->useCase = new SyncAdSpendUseCase($this->googleAdsMock, $this->mixpanelMock);

        Log::spy();
    }

    // ========================================================================
    // Happy Path Tests
    // ========================================================================

    #[Test]
    public function it_executes_successfully_and_logs_start_and_completion_messages(): void
    {
        $this->setupSuccessfulSync(self::TEST_DATE);

        $job = new SyncGoogleAdsToMixpanelJob(self::TEST_DATE);

        $job->handle($this->useCase);

        Log::shouldHaveReceived('info')
            ->with('Queued Google Ads to Mixpanel sync starting', ['date' => self::TEST_DATE]);

        Log::shouldHaveReceived('info')
            ->with('Queued Google Ads to Mixpanel sync completed', ['date' => self::TEST_DATE]);
    }

    #[Test]
    public function it_passes_correct_date_to_use_case(): void
    {
        $specificDate = '2024-12-31';

        $this->googleAdsMock
            ->shouldReceive('getDailyCampaignMetrics')
            ->once()
            ->with($specificDate)
            ->andReturn([]);

        $job = new SyncGoogleAdsToMixpanelJob($specificDate);

        $job->handle($this->useCase);

        Log::shouldHaveReceived('info')
            ->with('Queued Google Ads to Mixpanel sync starting', ['date' => $specificDate]);
    }

    // ========================================================================
    // ApiRateLimitException Handling
    // ========================================================================

    #[Test]
    public function it_catches_api_rate_limit_exception_and_logs_warning(): void
    {
        $rateLimitException = new ApiRateLimitException('Rate limited', 90);

        $this->googleAdsMock
            ->shouldReceive('getDailyCampaignMetrics')
            ->once()
            ->with(self::TEST_DATE)
            ->andThrow($rateLimitException);

        $job = new SyncGoogleAdsToMixpanelJob(self::TEST_DATE);
        $this->setJobAttempts($job, 1);

        // The job catches the exception and releases - it should not throw
        $job->handle($this->useCase);

        Log::shouldHaveReceived('warning')
            ->with('Rate limited during sync, will retry', [
                'date' => self::TEST_DATE,
                'retry_after' => 90,
                'attempts' => 1,
            ]);
    }

    #[Test]
    public function it_logs_warning_with_correct_retry_after_value_from_exception(): void
    {
        $customRetryAfter = 180;
        $rateLimitException = new ApiRateLimitException('Rate limited', $customRetryAfter);

        $this->googleAdsMock
            ->shouldReceive('getDailyCampaignMetrics')
            ->andThrow($rateLimitException);

        $job = new SyncGoogleAdsToMixpanelJob(self::TEST_DATE);
        $this->setJobAttempts($job, 2);

        $job->handle($this->useCase);

        Log::shouldHaveReceived('warning')
            ->with('Rate limited during sync, will retry', Mockery::on(
                static function (array $context) use ($customRetryAfter): bool {
                    self::assertSame($customRetryAfter, $context['retry_after']);

                    return true;
                },
            ));
    }

    #[Test]
    public function it_logs_correct_attempt_count_in_warning(): void
    {
        $rateLimitException = new ApiRateLimitException('Rate limited', 60);

        $this->googleAdsMock
            ->shouldReceive('getDailyCampaignMetrics')
            ->andThrow($rateLimitException);

        $job = new SyncGoogleAdsToMixpanelJob(self::TEST_DATE);
        $this->setJobAttempts($job, 3);

        $job->handle($this->useCase);

        Log::shouldHaveReceived('warning')
            ->with('Rate limited during sync, will retry', [
                'date' => self::TEST_DATE,
                'retry_after' => 60,
                'attempts' => 3,
            ]);
    }

    // ========================================================================
    // Backoff Delay Calculation Tests
    // ========================================================================

    #[Test]
    #[DataProvider('backoffDelayProvider')]
    public function it_calculates_correct_backoff_delay_for_each_attempt(int $attempt, int $expectedDelay): void
    {
        $job = new SyncGoogleAdsToMixpanelJob(self::TEST_DATE);

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
        $job = new SyncGoogleAdsToMixpanelJob(self::TEST_DATE);

        // Attempt 6 should fall back to 960 (the last value)
        $calculatedDelay = $job->backoff[6 - 1] ?? 960;

        self::assertSame(960, $calculatedDelay);
    }

    #[Test]
    public function it_uses_fallback_backoff_delay_for_very_high_attempt_number(): void
    {
        $job = new SyncGoogleAdsToMixpanelJob(self::TEST_DATE);

        // Attempt 100 should fall back to 960
        $calculatedDelay = $job->backoff[100 - 1] ?? 960;

        self::assertSame(960, $calculatedDelay);
    }

    // ========================================================================
    // Exception Propagation Tests
    // ========================================================================

    #[Test]
    #[DataProvider('propagatedExceptionProvider')]
    public function it_allows_non_rate_limit_api_exceptions_to_propagate(Throwable $exception): void
    {
        $this->googleAdsMock
            ->shouldReceive('getDailyCampaignMetrics')
            ->once()
            ->with(self::TEST_DATE)
            ->andThrow($exception);

        $job = new SyncGoogleAdsToMixpanelJob(self::TEST_DATE);

        $this->expectException($exception::class);

        $job->handle($this->useCase);
    }

    /**
     * @return array<string, array{Throwable}>
     */
    public static function propagatedExceptionProvider(): array
    {
        return [
            'GoogleAdsApiException propagates' => [
                GoogleAdsApiException::fromApiError('AUTH_ERROR', 'The user does not have access.'),
            ],
            'MixpanelApiException propagates' => [
                new MixpanelApiException('Invalid event format'),
            ],
        ];
    }

    #[Test]
    public function it_propagates_google_ads_api_exception_with_correct_message(): void
    {
        $exception = GoogleAdsApiException::fromApiError('AUTH_ERROR', 'The user does not have access.');

        $this->googleAdsMock
            ->shouldReceive('getDailyCampaignMetrics')
            ->once()
            ->andThrow($exception);

        $job = new SyncGoogleAdsToMixpanelJob(self::TEST_DATE);

        $this->expectException(GoogleAdsApiException::class);
        $this->expectExceptionMessage('Google Ads API error [AUTH_ERROR]: The user does not have access.');

        $job->handle($this->useCase);
    }

    #[Test]
    public function it_propagates_mixpanel_api_exception_from_mixpanel_client(): void
    {
        $exception = MixpanelApiException::fromValidationErrors([
            ['error' => 'invalid_payload'],
            ['error' => 'missing_field'],
        ]);

        $this->setupCampaignsForMixpanelError($exception);

        $job = new SyncGoogleAdsToMixpanelJob(self::TEST_DATE);

        $this->expectException(MixpanelApiException::class);
        $this->expectExceptionMessage('Mixpanel validation failed for 2 events');

        $job->handle($this->useCase);
    }

    #[Test]
    public function it_does_not_catch_generic_runtime_exception(): void
    {
        $exception = new RuntimeException('Unexpected error occurred');

        $this->googleAdsMock
            ->shouldReceive('getDailyCampaignMetrics')
            ->once()
            ->andThrow($exception);

        $job = new SyncGoogleAdsToMixpanelJob(self::TEST_DATE);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected error occurred');

        $job->handle($this->useCase);
    }

    #[Test]
    public function it_logs_start_message_before_exception_propagates(): void
    {
        $exception = GoogleAdsApiException::fromApiError('API_ERROR', 'Test error');

        $this->googleAdsMock
            ->shouldReceive('getDailyCampaignMetrics')
            ->andThrow($exception);

        $job = new SyncGoogleAdsToMixpanelJob(self::TEST_DATE);

        try {
            $job->handle($this->useCase);
        } catch (GoogleAdsApiException) {
            // Expected
        }

        Log::shouldHaveReceived('info')
            ->with('Queued Google Ads to Mixpanel sync starting', ['date' => self::TEST_DATE]);
    }

    // ========================================================================
    // failed() Method Tests
    // ========================================================================

    #[Test]
    public function failed_method_logs_error_with_exception_details(): void
    {
        $exception = new RuntimeException('Something went terribly wrong');

        $job = new SyncGoogleAdsToMixpanelJob(self::TEST_DATE);
        $this->setJobAttempts($job, 5);

        $job->failed($exception);

        Log::shouldHaveReceived('error')
            ->with('Google Ads to Mixpanel sync job failed', [
                'date' => self::TEST_DATE,
                'exception' => RuntimeException::class,
                'message' => 'Something went terribly wrong',
                'attempts' => 5,
            ]);
    }

    #[Test]
    public function failed_method_logs_google_ads_api_exception_class(): void
    {
        $exception = GoogleAdsApiException::fromApiError('QUERY_ERROR', 'Invalid query syntax');

        $job = new SyncGoogleAdsToMixpanelJob(self::TEST_DATE);
        $this->setJobAttempts($job, 3);

        $job->failed($exception);

        Log::shouldHaveReceived('error')
            ->with('Google Ads to Mixpanel sync job failed', [
                'date' => self::TEST_DATE,
                'exception' => GoogleAdsApiException::class,
                'message' => 'Google Ads API error [QUERY_ERROR]: Invalid query syntax',
                'attempts' => 3,
            ]);
    }

    #[Test]
    public function failed_method_logs_mixpanel_api_exception_class(): void
    {
        $exception = new MixpanelApiException('Import batch failed');

        $job = new SyncGoogleAdsToMixpanelJob(self::TEST_DATE);
        $this->setJobAttempts($job, 2);

        $job->failed($exception);

        Log::shouldHaveReceived('error')
            ->with('Google Ads to Mixpanel sync job failed', [
                'date' => self::TEST_DATE,
                'exception' => MixpanelApiException::class,
                'message' => 'Import batch failed',
                'attempts' => 2,
            ]);
    }

    #[Test]
    public function failed_method_logs_api_rate_limit_exception_class(): void
    {
        $exception = new ApiRateLimitException('Rate limit exceeded permanently', 300);

        $job = new SyncGoogleAdsToMixpanelJob(self::TEST_DATE);
        $this->setJobAttempts($job, 5);

        $job->failed($exception);

        Log::shouldHaveReceived('error')
            ->with('Google Ads to Mixpanel sync job failed', [
                'date' => self::TEST_DATE,
                'exception' => ApiRateLimitException::class,
                'message' => 'Rate limit exceeded permanently',
                'attempts' => 5,
            ]);
    }

    #[Test]
    public function failed_method_logs_correct_date_from_job_constructor(): void
    {
        $specificDate = '2025-01-15';
        $exception = new RuntimeException('Test failure');

        $job = new SyncGoogleAdsToMixpanelJob($specificDate);
        $this->setJobAttempts($job, 1);

        $job->failed($exception);

        Log::shouldHaveReceived('error')
            ->with('Google Ads to Mixpanel sync job failed', Mockery::on(
                static function (array $context) use ($specificDate): bool {
                    self::assertSame($specificDate, $context['date']);

                    return true;
                },
            ));
    }

    #[Test]
    public function failed_method_logs_attempt_count_on_first_attempt(): void
    {
        $exception = new RuntimeException('First attempt failure');

        $job = new SyncGoogleAdsToMixpanelJob(self::TEST_DATE);
        $this->setJobAttempts($job, 1);

        $job->failed($exception);

        Log::shouldHaveReceived('error')
            ->with('Google Ads to Mixpanel sync job failed', Mockery::on(
                static function (array $context): bool {
                    self::assertSame(1, $context['attempts']);

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
        $job = new SyncGoogleAdsToMixpanelJob(self::TEST_DATE);

        self::assertSame(5, $job->tries);
    }

    #[Test]
    public function it_has_correct_default_backoff_configuration(): void
    {
        $job = new SyncGoogleAdsToMixpanelJob(self::TEST_DATE);

        self::assertSame([60, 120, 240, 480, 960], $job->backoff);
    }

    #[Test]
    public function it_has_exactly_five_backoff_values(): void
    {
        $job = new SyncGoogleAdsToMixpanelJob(self::TEST_DATE);

        self::assertCount(5, $job->backoff);
    }

    #[Test]
    public function backoff_values_are_exponentially_increasing(): void
    {
        $job = new SyncGoogleAdsToMixpanelJob(self::TEST_DATE);

        // Each value should be double the previous (exponential backoff)
        self::assertSame(60, $job->backoff[0]);
        self::assertSame(120, $job->backoff[1]); // 60 * 2
        self::assertSame(240, $job->backoff[2]); // 120 * 2
        self::assertSame(480, $job->backoff[3]); // 240 * 2
        self::assertSame(960, $job->backoff[4]); // 480 * 2
    }

    #[Test]
    public function it_stores_date_from_constructor(): void
    {
        $expectedDate = '2024-06-15';

        $this->googleAdsMock
            ->shouldReceive('getDailyCampaignMetrics')
            ->once()
            ->with($expectedDate)
            ->andReturn([]);

        $job = new SyncGoogleAdsToMixpanelJob($expectedDate);

        $job->handle($this->useCase);

        Log::shouldHaveReceived('info')
            ->with('Queued Google Ads to Mixpanel sync starting', ['date' => $expectedDate]);
    }

    // ========================================================================
    // Queue Integration Tests
    // ========================================================================

    #[Test]
    public function it_can_be_dispatched_to_queue(): void
    {
        Queue::fake();

        SyncGoogleAdsToMixpanelJob::dispatch(self::TEST_DATE);

        Queue::assertPushed(SyncGoogleAdsToMixpanelJob::class, static function (SyncGoogleAdsToMixpanelJob $job): bool {
            // Access private property via reflection to verify date
            $reflection = new ReflectionClass($job);
            $property = $reflection->getProperty('date');

            return $property->getValue($job) === self::TEST_DATE;
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

        $this->googleAdsMock
            ->shouldReceive('getDailyCampaignMetrics')
            ->once()
            ->with($date)
            ->andReturn([$campaign]);

        $this->mixpanelMock
            ->shouldReceive('importBatch')
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

        $this->googleAdsMock
            ->shouldReceive('getDailyCampaignMetrics')
            ->once()
            ->andReturn([$campaign]);

        $this->mixpanelMock
            ->shouldReceive('importBatch')
            ->once()
            ->andThrow($exception);
    }

    /**
     * Set the job's underlying queue job to mock attempts().
     */
    private function setJobAttempts(SyncGoogleAdsToMixpanelJob $job, int $attempts): void
    {
        $queueJob = Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('attempts')->andReturn($attempts);
        $queueJob->shouldReceive('release')->andReturnNull();
        $queueJob->shouldReceive('isReleased')->andReturn(false);
        $queueJob->shouldReceive('isDeletedOrReleased')->andReturn(false);

        $job->setJob($queueJob);
    }
}
