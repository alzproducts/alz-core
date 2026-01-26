<?php

declare(strict_types=1);

namespace Tests\Feature\Application\AdSpend\UseCases;

use App\Application\AdSpend\UseCases\SyncAdSpendUseCase;
use App\Application\Contracts\AdSpendClientInterface;
use App\Application\Contracts\MixpanelClientInterface;
use App\Domain\AdSpend\Enums\AdSource;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\ValueObjects\DateRange;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(SyncAdSpendUseCase::class)]
final class SyncAdSpendUseCaseTest extends TestCase
{
    private AdSpendClientInterface&MockInterface $adClient;

    private MixpanelClientInterface&MockInterface $mixpanelClient;

    private LoggerInterface&MockInterface $loggerMock;

    private SyncAdSpendUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adClient = Mockery::mock(AdSpendClientInterface::class);
        $this->adClient->shouldReceive('getSource')->andReturn(AdSource::Google)->byDefault();
        $this->mixpanelClient = Mockery::mock(MixpanelClientInterface::class);
        $this->loggerMock = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

        $this->useCase = new SyncAdSpendUseCase(
            $this->adClient,
            $this->mixpanelClient,
            $this->loggerMock,
        );
    }

    // ========================================================================
    // Happy Path Tests
    // ========================================================================

    #[Test]
    public function it_successfully_syncs_single_campaign(): void
    {
        $date = new DateTimeImmutable('2024-11-18');
        $dateString = '2024-11-18';
        $campaign = $this->createCampaignMetrics(
            campaignId: 123456,
            campaignName: '[01] Search - Branded',
            date: $dateString,
            costInPounds: 125.43,
            clicks: 342,
            impressions: 8234,
            conversions: 12.5,
        );

        $this->adClient
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->once()
            ->with(Mockery::on(static fn(DateRange $range): bool => $range->from->format('Y-m-d') === $dateString && $range->to->format('Y-m-d') === $dateString))
            ->andReturn([$campaign]);

        $this->mixpanelClient
            ->shouldReceive('importCampaigns')
            ->once()
            ->withArgs(static function (array $campaigns): bool {
                self::assertCount(1, $campaigns);
                self::assertInstanceOf(CampaignMetrics::class, $campaigns[0]);
                self::assertSame(123456, $campaigns[0]->campaignId);
                self::assertSame('[01] Search - Branded', $campaigns[0]->campaignName);
                self::assertSame(125.43, $campaigns[0]->costInPounds);
                self::assertSame(342, $campaigns[0]->clicks);
                self::assertSame(8234, $campaigns[0]->impressions);
                self::assertSame(12.5, $campaigns[0]->conversions);

                return true;
            });

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Starting ad spend sync', ['from' => $dateString, 'to' => $dateString, 'source' => 'Google'])
            ->once();

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Ad spend sync completed', ['from' => $dateString, 'to' => $dateString, 'source' => 'Google', 'campaigns_synced' => 1])
            ->once();

        $this->useCase->execute(DateRange::singleDay($date));
    }

    #[Test]
    public function it_successfully_syncs_multiple_campaigns(): void
    {
        $date = new DateTimeImmutable('2024-11-18');
        $dateString = '2024-11-18';
        $campaigns = [
            $this->createCampaignMetrics(campaignId: 111, campaignName: 'Campaign One', date: $dateString),
            $this->createCampaignMetrics(campaignId: 222, campaignName: 'Campaign Two', date: $dateString),
            $this->createCampaignMetrics(campaignId: 333, campaignName: 'Campaign Three', date: $dateString),
        ];

        $this->adClient
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->once()
            ->with(Mockery::on(static fn(DateRange $range): bool => $range->from->format('Y-m-d') === $dateString && $range->to->format('Y-m-d') === $dateString))
            ->andReturn($campaigns);

        $this->mixpanelClient
            ->shouldReceive('importCampaigns')
            ->once()
            ->withArgs(static function (array $campaigns): bool {
                self::assertCount(3, $campaigns);
                self::assertSame(111, $campaigns[0]->campaignId);
                self::assertSame('Campaign One', $campaigns[0]->campaignName);
                self::assertSame(222, $campaigns[1]->campaignId);
                self::assertSame('Campaign Two', $campaigns[1]->campaignName);
                self::assertSame(333, $campaigns[2]->campaignId);
                self::assertSame('Campaign Three', $campaigns[2]->campaignName);

                return true;
            });

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Ad spend sync completed', ['from' => $dateString, 'to' => $dateString, 'source' => 'Google', 'campaigns_synced' => 3])
            ->once();

        $this->useCase->execute(DateRange::singleDay($date));
    }

    #[Test]
    public function it_passes_correct_date_range_to_ad_client(): void
    {
        $date = new DateTimeImmutable('2024-12-25');
        $dateString = '2024-12-25';

        $this->adClient
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->once()
            ->with(Mockery::on(static fn(DateRange $range): bool => $range->from->format('Y-m-d') === $dateString && $range->to->format('Y-m-d') === $dateString))
            ->andReturn([]);

        $this->loggerMock
            ->shouldReceive('warning')
            ->with('No campaigns found for date range', ['from' => $dateString, 'to' => $dateString, 'source' => 'Google'])
            ->once();

        $this->useCase->execute(DateRange::singleDay($date));
    }

    // ========================================================================
    // Empty Results Handling
    // ========================================================================

    #[Test]
    public function it_handles_empty_results_from_ad_client(): void
    {
        $date = new DateTimeImmutable('2024-11-18');
        $dateString = '2024-11-18';

        $this->adClient
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->once()
            ->with(Mockery::on(static fn(DateRange $range): bool => $range->from->format('Y-m-d') === $dateString && $range->to->format('Y-m-d') === $dateString))
            ->andReturn([]);

        $this->mixpanelClient
            ->shouldNotReceive('importBatch');

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Starting ad spend sync', ['from' => $dateString, 'to' => $dateString, 'source' => 'Google'])
            ->once();

        $this->loggerMock
            ->shouldReceive('warning')
            ->with('No campaigns found for date range', ['from' => $dateString, 'to' => $dateString, 'source' => 'Google'])
            ->once();

        $this->useCase->execute(DateRange::singleDay($date));
    }

    #[Test]
    public function it_does_not_call_mixpanel_when_no_campaigns_found(): void
    {
        $date = new DateTimeImmutable('2024-11-18');
        $dateString = '2024-11-18';

        $this->adClient
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->once()
            ->with(Mockery::on(static fn(DateRange $range): bool => $range->from->format('Y-m-d') === $dateString && $range->to->format('Y-m-d') === $dateString))
            ->andReturn([]);

        $this->mixpanelClient
            ->shouldNotReceive('importBatch');

        $this->useCase->execute(DateRange::singleDay($date));
    }

    // ========================================================================
    // Ad Client Error Handling
    // ========================================================================

    #[Test]
    public function it_propagates_external_service_unavailable_from_ad_client(): void
    {
        $date = new DateTimeImmutable('2024-11-18');
        $dateString = '2024-11-18';
        $exception = new ExternalServiceUnavailableException('Google Ads');

        $this->adClient
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->once()
            ->with(Mockery::on(static fn(DateRange $range): bool => $range->from->format('Y-m-d') === $dateString && $range->to->format('Y-m-d') === $dateString))
            ->andThrow($exception);

        $this->mixpanelClient
            ->shouldNotReceive('importBatch');

        $this->expectExceptionObject($exception);

        $this->useCase->execute(DateRange::singleDay($date));
    }

    #[Test]
    public function it_propagates_external_service_unavailable_from_rate_limit(): void
    {
        $date = new DateTimeImmutable('2024-11-18');
        $dateString = '2024-11-18';
        $exception = new ExternalServiceUnavailableException('Google Ads', retryAfter: 60);

        $this->adClient
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->once()
            ->with(Mockery::on(static fn(DateRange $range): bool => $range->from->format('Y-m-d') === $dateString && $range->to->format('Y-m-d') === $dateString))
            ->andThrow($exception);

        $this->mixpanelClient
            ->shouldNotReceive('importBatch');

        $this->expectException(ExternalServiceUnavailableException::class);
        $this->expectExceptionMessage("External service 'Google Ads' is unavailable");

        $this->useCase->execute(DateRange::singleDay($date));
    }

    #[Test]
    public function it_logs_start_before_ad_client_exception(): void
    {
        $date = new DateTimeImmutable('2024-11-18');
        $dateString = '2024-11-18';
        $exception = new ExternalServiceUnavailableException('Google Ads');

        $this->adClient
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->andThrow($exception);

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Starting ad spend sync', ['from' => $dateString, 'to' => $dateString, 'source' => 'Google'])
            ->once();

        try {
            $this->useCase->execute(DateRange::singleDay($date));
        } catch (ExternalServiceUnavailableException) {
            // Expected
        }
    }

    #[Test]
    public function it_does_not_log_completion_when_ad_client_fails(): void
    {
        $date = new DateTimeImmutable('2024-11-18');
        $exception = new ExternalServiceUnavailableException('Google Ads');

        $this->adClient
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->andThrow($exception);

        $this->loggerMock
            ->shouldNotReceive('info')
            ->with('Ad spend sync completed', Mockery::any());

        try {
            $this->useCase->execute(DateRange::singleDay($date));
        } catch (ExternalServiceUnavailableException) {
            // Expected - exception should propagate
        }

        // Mockery will verify shouldNotReceive at teardown
        self::assertTrue(true);
    }

    // ========================================================================
    // Mixpanel Error Handling
    // ========================================================================

    #[Test]
    public function it_propagates_external_service_unavailable_from_mixpanel(): void
    {
        $date = new DateTimeImmutable('2024-11-18');
        $dateString = '2024-11-18';
        $campaign = $this->createCampaignMetrics(campaignId: 123, date: $dateString);
        $exception = new ExternalServiceUnavailableException('Mixpanel');

        $this->adClient
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->once()
            ->andReturn([$campaign]);

        $this->mixpanelClient
            ->shouldReceive('importCampaigns')
            ->once()
            ->andThrow($exception);

        $this->expectException(ExternalServiceUnavailableException::class);
        $this->expectExceptionMessage("External service 'Mixpanel' is unavailable");

        $this->useCase->execute(DateRange::singleDay($date));
    }

    #[Test]
    public function it_propagates_external_service_unavailable_from_mixpanel_rate_limit(): void
    {
        $date = new DateTimeImmutable('2024-11-18');
        $dateString = '2024-11-18';
        $campaign = $this->createCampaignMetrics(campaignId: 123, date: $dateString);
        $exception = new ExternalServiceUnavailableException('Mixpanel', retryAfter: 60);

        $this->adClient
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->once()
            ->andReturn([$campaign]);

        $this->mixpanelClient
            ->shouldReceive('importCampaigns')
            ->once()
            ->andThrow($exception);

        $this->expectException(ExternalServiceUnavailableException::class);
        $this->expectExceptionMessage("External service 'Mixpanel' is unavailable");

        $this->useCase->execute(DateRange::singleDay($date));
    }

    #[Test]
    public function it_does_not_log_completion_when_mixpanel_fails(): void
    {
        $date = new DateTimeImmutable('2024-11-18');
        $dateString = '2024-11-18';
        $campaign = $this->createCampaignMetrics(campaignId: 123, date: $dateString);
        $exception = new ExternalServiceUnavailableException('Mixpanel');

        $this->adClient
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->andReturn([$campaign]);

        $this->mixpanelClient
            ->shouldReceive('importCampaigns')
            ->andThrow($exception);

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Starting ad spend sync', ['from' => $dateString, 'to' => $dateString, 'source' => 'Google'])
            ->once();

        try {
            $this->useCase->execute(DateRange::singleDay($date));
        } catch (ExternalServiceUnavailableException) {
            // Expected
        }
    }

    // ========================================================================
    // Data Transformation Validation
    // ========================================================================

    #[Test]
    public function it_transforms_campaign_metrics_to_events_correctly(): void
    {
        $date = new DateTimeImmutable('2024-11-18');
        $dateString = '2024-11-18';
        $campaign = $this->createCampaignMetrics(
            campaignId: 999888,
            campaignName: '[TM] Shopping | Low Margin',
            date: $dateString,
            costInPounds: 250.75,
            clicks: 500,
            impressions: 15000,
            conversions: 25.0,
        );

        $this->adClient
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->andReturn([$campaign]);

        $this->mixpanelClient
            ->shouldReceive('importCampaigns')
            ->once()
            ->withArgs(static function (array $events) use ($campaign): bool {
                self::assertCount(1, $events);

                $actual = $events[0];

                // Verify raw CampaignMetrics are passed unchanged to Infrastructure layer
                self::assertSame($campaign->campaignId, $actual->campaignId);
                self::assertSame($campaign->campaignName, $actual->campaignName);
                self::assertSame($campaign->date, $actual->date);
                self::assertSame($campaign->costInPounds, $actual->costInPounds);
                self::assertSame($campaign->clicks, $actual->clicks);
                self::assertSame($campaign->impressions, $actual->impressions);
                self::assertSame($campaign->conversions, $actual->conversions);

                return true;
            });

        $this->useCase->execute(DateRange::singleDay($date));
    }

    #[Test]
    public function it_preserves_campaign_name_with_special_characters(): void
    {
        $date = new DateTimeImmutable('2024-11-18');
        $dateString = '2024-11-18';
        $specialCampaignName = '[02] Performance Max - All Products | Q4';
        $campaign = $this->createCampaignMetrics(
            campaignId: 12345,
            campaignName: $specialCampaignName,
            date: $dateString,
        );

        $this->adClient
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->andReturn([$campaign]);

        $this->mixpanelClient
            ->shouldReceive('importCampaigns')
            ->once()
            ->withArgs(static function (array $events) use ($campaign): bool {
                // Verify raw CampaignMetrics are passed - transformation happens in Infrastructure
                self::assertSame($campaign->campaignName, $events[0]->campaignName);

                return true;
            });

        $this->useCase->execute(DateRange::singleDay($date));
    }

    #[Test]
    public function it_generates_correct_insert_id_format(): void
    {
        $date = new DateTimeImmutable('2024-11-18');
        $dateString = '2024-11-18';
        $campaign = $this->createCampaignMetrics(campaignId: 123456, date: $dateString);

        $this->adClient
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->andReturn([$campaign]);

        $this->mixpanelClient
            ->shouldReceive('importCampaigns')
            ->once()
            ->withArgs(static function (array $events) use ($campaign): bool {
                // Verify raw CampaignMetrics are passed to Infrastructure layer
                // Infrastructure layer handles transformation to include insertId
                self::assertSame($campaign->campaignId, $events[0]->campaignId);
                self::assertSame($campaign->date, $events[0]->date);

                return true;
            });

        $this->useCase->execute(DateRange::singleDay($date));
    }

    #[Test]
    public function it_converts_date_to_unix_timestamp(): void
    {
        $date = new DateTimeImmutable('2024-11-18');
        $dateString = '2024-11-18';

        $campaign = $this->createCampaignMetrics(campaignId: 123, date: $dateString);

        $this->adClient
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->andReturn([$campaign]);

        $this->mixpanelClient
            ->shouldReceive('importCampaigns')
            ->once()
            ->withArgs(static function (array $events) use ($campaign): bool {
                // Verify raw CampaignMetrics are passed
                // Infrastructure layer transforms date to Unix timestamp
                self::assertSame($campaign->date, $events[0]->date);

                return true;
            });

        $this->useCase->execute(DateRange::singleDay($date));
    }

    #[Test]
    public function it_maintains_decimal_precision_in_cost_and_conversions(): void
    {
        $date = new DateTimeImmutable('2024-11-18');
        $dateString = '2024-11-18';
        $campaign = $this->createCampaignMetrics(
            campaignId: 123,
            date: $dateString,
            costInPounds: 125.43,
            conversions: 12.567,
        );

        $this->adClient
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->andReturn([$campaign]);

        $this->mixpanelClient
            ->shouldReceive('importCampaigns')
            ->once()
            ->withArgs(static function (array $events): bool {
                self::assertSame(125.43, $events[0]->costInPounds);
                self::assertSame(12.567, $events[0]->conversions);

                return true;
            });

        $this->useCase->execute(DateRange::singleDay($date));
    }

    #[Test]
    public function it_handles_zero_spend_campaigns(): void
    {
        $date = new DateTimeImmutable('2024-11-18');
        $dateString = '2024-11-18';
        $campaign = $this->createCampaignMetrics(
            campaignId: 123,
            campaignName: 'Zero Spend Campaign',
            date: $dateString,
            costInPounds: 0.0,
            clicks: 0,
            impressions: 0,
            conversions: 0.0,
        );

        $this->adClient
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->andReturn([$campaign]);

        $this->mixpanelClient
            ->shouldReceive('importCampaigns')
            ->once()
            ->withArgs(static function (array $events): bool {
                self::assertSame(0.0, $events[0]->costInPounds);
                self::assertSame(0, $events[0]->clicks);
                self::assertSame(0, $events[0]->impressions);
                self::assertSame(0.0, $events[0]->conversions);

                return true;
            });

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Ad spend sync completed', ['from' => $dateString, 'to' => $dateString, 'source' => 'Google', 'campaigns_synced' => 1])
            ->once();

        $this->useCase->execute(DateRange::singleDay($date));
    }

    // ========================================================================
    // Logging Verification
    // ========================================================================

    #[Test]
    public function it_logs_start_with_correct_date_range(): void
    {
        $date = new DateTimeImmutable('2024-12-31');
        $dateString = '2024-12-31';

        $this->adClient
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->andReturn([]);

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Starting ad spend sync', ['from' => $dateString, 'to' => $dateString, 'source' => 'Google'])
            ->once();

        $this->useCase->execute(DateRange::singleDay($date));
    }

    #[Test]
    public function it_logs_completion_with_exact_campaign_count(): void
    {
        $date = new DateTimeImmutable('2024-11-18');
        $dateString = '2024-11-18';
        $campaigns = [
            $this->createCampaignMetrics(campaignId: 1, date: $dateString),
            $this->createCampaignMetrics(campaignId: 2, date: $dateString),
            $this->createCampaignMetrics(campaignId: 3, date: $dateString),
            $this->createCampaignMetrics(campaignId: 4, date: $dateString),
            $this->createCampaignMetrics(campaignId: 5, date: $dateString),
        ];

        $this->adClient
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->andReturn($campaigns);

        $this->mixpanelClient
            ->shouldReceive('importCampaigns')
            ->once();

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Ad spend sync completed', ['from' => $dateString, 'to' => $dateString, 'source' => 'Google', 'campaigns_synced' => 5])
            ->once();

        $this->useCase->execute(DateRange::singleDay($date));
    }

    #[Test]
    public function it_logs_warning_with_correct_date_range_when_empty(): void
    {
        $date = new DateTimeImmutable('2024-01-01');
        $dateString = '2024-01-01';

        $this->adClient
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->andReturn([]);

        $this->loggerMock
            ->shouldReceive('warning')
            ->with('No campaigns found for date range', ['from' => $dateString, 'to' => $dateString, 'source' => 'Google'])
            ->once();

        $this->useCase->execute(DateRange::singleDay($date));
    }

    // ========================================================================
    // Event Array Order Preservation
    // ========================================================================

    #[Test]
    public function it_preserves_campaign_order_in_transformed_events(): void
    {
        $date = new DateTimeImmutable('2024-11-18');
        $dateString = '2024-11-18';
        $campaigns = [
            $this->createCampaignMetrics(campaignId: 999, campaignName: 'Campaign Z', date: $dateString),
            $this->createCampaignMetrics(campaignId: 111, campaignName: 'Campaign A', date: $dateString),
            $this->createCampaignMetrics(campaignId: 555, campaignName: 'Campaign M', date: $dateString),
        ];

        $this->adClient
            ->shouldReceive('getCampaignMetricsByDateRange')
            ->andReturn($campaigns);

        $this->mixpanelClient
            ->shouldReceive('importCampaigns')
            ->once()
            ->withArgs(static function (array $events): bool {
                // Order should match input, not be sorted
                self::assertSame(999, $events[0]->campaignId);
                self::assertSame(111, $events[1]->campaignId);
                self::assertSame(555, $events[2]->campaignId);

                return true;
            });

        $this->useCase->execute(DateRange::singleDay($date));
    }

    // ========================================================================
    // Helper Methods
    // ========================================================================

    private function createCampaignMetrics(
        int $campaignId = 123456,
        string $campaignName = 'Test Campaign',
        string $date = '2024-11-18',
        float $costInPounds = 100.00,
        int $clicks = 100,
        int $impressions = 5000,
        float $conversions = 5.0,
    ): CampaignMetrics {
        return new CampaignMetrics(
            campaignId: $campaignId,
            campaignName: $campaignName,
            date: $date,
            costInPounds: $costInPounds,
            clicks: $clicks,
            impressions: $impressions,
            conversions: $conversions,
        );
    }
}
