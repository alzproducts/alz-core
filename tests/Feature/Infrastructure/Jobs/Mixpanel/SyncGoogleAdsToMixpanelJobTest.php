<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Jobs\Mixpanel;

use App\Application\AdSpend\UseCases\SyncAdSpendUseCase;
use App\Application\Contracts\AdSpendClientInterface;
use App\Application\Contracts\MixpanelClientInterface;
use App\Domain\AdSpend\Enums\AdSource;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Domain\ValueObjects\DateRange;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use App\Infrastructure\Jobs\Mixpanel\SyncGoogleAdsToMixpanelJob;
use DateTimeImmutable;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Tests\TestCase;

/**
 * SyncGoogleAdsToMixpanelJob Feature Tests.
 *
 * Tests the job's success path and middleware configuration.
 * Exception handling (transient/permanent/unexpected) is tested in HandleApiExceptionsTest.
 * Failed callback logging is handled by the middleware.
 */
#[CoversClass(SyncGoogleAdsToMixpanelJob::class)]
final class SyncGoogleAdsToMixpanelJobTest extends TestCase
{
    private AdSpendClientInterface&MockInterface $adClientMock;

    private MixpanelClientInterface&MockInterface $mixpanelMock;

    private SyncAdSpendUseCase $useCase;

    private const string TEST_DATE = '2024-11-20';

    protected function setUp(): void
    {
        parent::setUp();

        $this->adClientMock = Mockery::mock(AdSpendClientInterface::class);
        $this->adClientMock->shouldReceive('getSource')->andReturn(AdSource::Google)->byDefault();
        $this->mixpanelMock = Mockery::mock(MixpanelClientInterface::class);
        $loggerMock = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
        $this->useCase = new SyncAdSpendUseCase($this->adClientMock, $this->mixpanelMock, $loggerMock);
    }

    // ========================================================================
    // Middleware Tests
    // ========================================================================

    #[Test]
    public function it_returns_correct_middleware(): void
    {
        $job = new SyncGoogleAdsToMixpanelJob(new DateTimeImmutable(self::TEST_DATE), new DateTimeImmutable(self::TEST_DATE));
        $middleware = $job->middleware();

        $this->assertCount(2, $middleware);
        $this->assertInstanceOf(ServiceCircuitBreaker::class, $middleware[0]);
        $this->assertInstanceOf(HandleApiExceptions::class, $middleware[1]);
    }

    // ========================================================================
    // Happy Path Tests
    // ========================================================================

    #[Test]
    public function it_executes_successfully(): void
    {
        $this->setupSuccessfulSync(self::TEST_DATE);

        $job = new SyncGoogleAdsToMixpanelJob(new DateTimeImmutable(self::TEST_DATE), new DateTimeImmutable(self::TEST_DATE));

        $job->handle($this->useCase);
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

        $job->handle($this->useCase);
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

        $job->handle($this->useCase);
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

}
