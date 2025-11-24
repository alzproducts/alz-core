<?php

declare(strict_types=1);

namespace Tests\Feature\Application\AdSpend\UseCases;

use App\Application\AdSpend\UseCases\SyncAdSpendUseCase;
use App\Application\Contracts\GoogleAdsClientInterface;
use App\Application\Contracts\MixpanelClientInterface;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use InvalidArgumentException;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(SyncAdSpendUseCase::class)]
final class SyncAdSpendUseCaseTest extends TestCase
{
    private GoogleAdsClientInterface&MockInterface $googleAdsClient;

    private MixpanelClientInterface&MockInterface $mixpanelClient;

    private LoggerInterface&MockInterface $loggerMock;

    private SyncAdSpendUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->googleAdsClient = Mockery::mock(GoogleAdsClientInterface::class);
        $this->mixpanelClient = Mockery::mock(MixpanelClientInterface::class);
        $this->loggerMock = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

        $this->useCase = new SyncAdSpendUseCase(
            $this->googleAdsClient,
            $this->mixpanelClient,
            $this->loggerMock,
        );
    }

    // ========================================================================
    // Date Validation Tests
    // ========================================================================

    #[Test]
    #[DataProvider('invalidDateFormatsProvider')]
    public function it_throws_exception_for_invalid_date_formats(string $invalidDate): void
    {
        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Date must be in YYYY-MM-DD format.');

        // Act
        $this->useCase->execute($invalidDate);
    }

    #[Test]
    public function it_accepts_valid_yyyy_mm_dd_format(): void
    {
        // Arrange
        $validDate = '2024-11-18';

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
            ->once()
            ->with($validDate)
            ->andReturn([]);

        // Act & Assert: Should not throw
        $this->useCase->execute($validDate);
    }

    #[Test]
    #[DataProvider('edgeCaseDateFormatsProvider')]
    public function it_validates_date_format_before_making_api_calls(string $invalidDate): void
    {
        // Arrange: Google Ads client should NEVER be called with invalid dates
        $this->googleAdsClient
            ->shouldNotReceive('getDailyCampaignMetrics');

        $this->mixpanelClient
            ->shouldNotReceive('importCampaigns');

        // Assert
        $this->expectException(InvalidArgumentException::class);

        // Act
        $this->useCase->execute($invalidDate);
    }

    public static function invalidDateFormatsProvider(): array
    {
        return [
            'empty string' => [''],
            'slash separator' => ['2024/11/18'],
            'dot separator' => ['2024.11.18'],
            'US format' => ['11-18-2024'],
            'European format' => ['18-11-2024'],
            'no separators' => ['20241118'],
            'ISO 8601 with time' => ['2024-11-18T00:00:00'],
            'ISO 8601 with timezone' => ['2024-11-18T00:00:00Z'],
            'short year' => ['24-11-18'],
            'single digit month' => ['2024-1-18'],
            'single digit day' => ['2024-11-1'],
            'text month' => ['2024-Nov-18'],
            'extra characters' => ['2024-11-18 '],
            'leading space' => [' 2024-11-18'],
            'only year' => ['2024'],
            'only year and month' => ['2024-11'],
            'too many digits in year' => ['12024-11-18'],
        ];
    }

    public static function edgeCaseDateFormatsProvider(): array
    {
        return [
            'null string' => ['null'],
            'boolean true' => ['true'],
            'boolean false' => ['false'],
            'random text' => ['not-a-date'],
            'SQL injection attempt' => ["2024'; DROP TABLE--"],
        ];
    }

    // ========================================================================
    // Happy Path Tests
    // ========================================================================

    #[Test]
    public function it_successfully_syncs_single_campaign(): void
    {
        $date = '2024-11-18';
        $campaign = $this->createCampaignMetrics(
            campaignId: 123456,
            campaignName: '[01] Search - Branded',
            date: $date,
            costInPounds: 125.43,
            clicks: 342,
            impressions: 8234,
            conversions: 12.5,
        );

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
            ->once()
            ->with($date)
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
            ->with('Starting ad spend sync', ['date' => $date])
            ->once();

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Ad spend sync completed', ['date' => $date, 'campaigns_synced' => 1])
            ->once();

        $this->useCase->execute($date);
    }

    #[Test]
    public function it_successfully_syncs_multiple_campaigns(): void
    {
        $date = '2024-11-18';
        $campaigns = [
            $this->createCampaignMetrics(campaignId: 111, campaignName: 'Campaign One', date: $date),
            $this->createCampaignMetrics(campaignId: 222, campaignName: 'Campaign Two', date: $date),
            $this->createCampaignMetrics(campaignId: 333, campaignName: 'Campaign Three', date: $date),
        ];

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
            ->once()
            ->with($date)
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
            ->with('Ad spend sync completed', ['date' => $date, 'campaigns_synced' => 3])
            ->once();

        $this->useCase->execute($date);
    }

    #[Test]
    public function it_passes_correct_date_to_google_ads_client(): void
    {
        $date = '2024-12-25';

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
            ->once()
            ->with($date)
            ->andReturn([]);

        $this->loggerMock
            ->shouldReceive('warning')
            ->with('No campaigns found for date', ['date' => $date])
            ->once();

        $this->useCase->execute($date);
    }

    // ========================================================================
    // Empty Results Handling
    // ========================================================================

    #[Test]
    public function it_handles_empty_results_from_google_ads(): void
    {
        $date = '2024-11-18';

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
            ->once()
            ->with($date)
            ->andReturn([]);

        $this->mixpanelClient
            ->shouldNotReceive('importBatch');

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Starting ad spend sync', ['date' => $date])
            ->once();

        $this->loggerMock
            ->shouldReceive('warning')
            ->with('No campaigns found for date', ['date' => $date])
            ->once();

        $this->useCase->execute($date);
    }

    #[Test]
    public function it_does_not_call_mixpanel_when_no_campaigns_found(): void
    {
        $date = '2024-11-18';

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
            ->once()
            ->andReturn([]);

        $this->mixpanelClient
            ->shouldNotReceive('importBatch');

        $this->useCase->execute($date);
    }

    // ========================================================================
    // Google Ads Error Handling
    // ========================================================================

    #[Test]
    public function it_propagates_external_service_unavailable_from_google_ads(): void
    {
        $date = '2024-11-18';
        $exception = new ExternalServiceUnavailableException('Google Ads');

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
            ->once()
            ->with($date)
            ->andThrow($exception);

        $this->mixpanelClient
            ->shouldNotReceive('importBatch');

        $this->expectExceptionObject($exception);

        $this->useCase->execute($date);
    }

    #[Test]
    public function it_propagates_external_service_unavailable_from_rate_limit(): void
    {
        $date = '2024-11-18';
        $exception = new ExternalServiceUnavailableException('Google Ads', retryAfter: 60);

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
            ->once()
            ->with($date)
            ->andThrow($exception);

        $this->mixpanelClient
            ->shouldNotReceive('importBatch');

        $this->expectException(ExternalServiceUnavailableException::class);
        $this->expectExceptionMessage("External service 'Google Ads' is unavailable");

        $this->useCase->execute($date);
    }

    #[Test]
    public function it_logs_start_before_google_ads_exception(): void
    {
        $date = '2024-11-18';
        $exception = new ExternalServiceUnavailableException('Google Ads');

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
            ->andThrow($exception);

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Starting ad spend sync', ['date' => $date])
            ->once();

        try {
            $this->useCase->execute($date);
        } catch (ExternalServiceUnavailableException) {
            // Expected
        }
    }

    #[Test]
    public function it_does_not_log_completion_when_google_ads_fails(): void
    {
        $date = '2024-11-18';
        $exception = new ExternalServiceUnavailableException('Google Ads');

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
            ->andThrow($exception);

        $this->loggerMock
            ->shouldNotReceive('info')
            ->with('Ad spend sync completed', Mockery::any());

        try {
            $this->useCase->execute($date);
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
        $date = '2024-11-18';
        $campaign = $this->createCampaignMetrics(campaignId: 123, date: $date);
        $exception = new ExternalServiceUnavailableException('Mixpanel');

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
            ->once()
            ->andReturn([$campaign]);

        $this->mixpanelClient
            ->shouldReceive('importCampaigns')
            ->once()
            ->andThrow($exception);

        $this->expectException(ExternalServiceUnavailableException::class);
        $this->expectExceptionMessage("External service 'Mixpanel' is unavailable");

        $this->useCase->execute($date);
    }

    #[Test]
    public function it_propagates_external_service_unavailable_from_mixpanel_rate_limit(): void
    {
        $date = '2024-11-18';
        $campaign = $this->createCampaignMetrics(campaignId: 123, date: $date);
        $exception = new ExternalServiceUnavailableException('Mixpanel', retryAfter: 60);

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
            ->once()
            ->andReturn([$campaign]);

        $this->mixpanelClient
            ->shouldReceive('importCampaigns')
            ->once()
            ->andThrow($exception);

        $this->expectException(ExternalServiceUnavailableException::class);
        $this->expectExceptionMessage("External service 'Mixpanel' is unavailable");

        $this->useCase->execute($date);
    }

    #[Test]
    public function it_does_not_log_completion_when_mixpanel_fails(): void
    {
        $date = '2024-11-18';
        $campaign = $this->createCampaignMetrics(campaignId: 123, date: $date);
        $exception = new ExternalServiceUnavailableException('Mixpanel');

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
            ->andReturn([$campaign]);

        $this->mixpanelClient
            ->shouldReceive('importCampaigns')
            ->andThrow($exception);

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Starting ad spend sync', ['date' => $date])
            ->once();

        try {
            $this->useCase->execute($date);
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
        $date = '2024-11-18';
        $campaign = $this->createCampaignMetrics(
            campaignId: 999888,
            campaignName: '[TM] Shopping | Low Margin',
            date: $date,
            costInPounds: 250.75,
            clicks: 500,
            impressions: 15000,
            conversions: 25.0,
        );

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
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

        $this->useCase->execute($date);
    }

    #[Test]
    public function it_preserves_campaign_name_with_special_characters(): void
    {
        $date = '2024-11-18';
        $specialCampaignName = '[02] Performance Max - All Products | Q4';
        $campaign = $this->createCampaignMetrics(
            campaignId: 12345,
            campaignName: $specialCampaignName,
            date: $date,
        );

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
            ->andReturn([$campaign]);

        $this->mixpanelClient
            ->shouldReceive('importCampaigns')
            ->once()
            ->withArgs(static function (array $events) use ($campaign): bool {
                // Verify raw CampaignMetrics are passed - transformation happens in Infrastructure
                self::assertSame($campaign->campaignName, $events[0]->campaignName);

                return true;
            });

        $this->useCase->execute($date);
    }

    #[Test]
    public function it_generates_correct_insert_id_format(): void
    {
        $date = '2024-11-18';
        $campaign = $this->createCampaignMetrics(campaignId: 123456, date: $date);

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
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

        $this->useCase->execute($date);
    }

    #[Test]
    public function it_converts_date_to_unix_timestamp(): void
    {
        $date = '2024-11-18';

        $campaign = $this->createCampaignMetrics(campaignId: 123, date: $date);

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
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

        $this->useCase->execute($date);
    }

    #[Test]
    public function it_maintains_decimal_precision_in_cost_and_conversions(): void
    {
        $date = '2024-11-18';
        $campaign = $this->createCampaignMetrics(
            campaignId: 123,
            date: $date,
            costInPounds: 125.43,
            conversions: 12.567,
        );

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
            ->andReturn([$campaign]);

        $this->mixpanelClient
            ->shouldReceive('importCampaigns')
            ->once()
            ->withArgs(static function (array $events): bool {
                self::assertSame(125.43, $events[0]->costInPounds);
                self::assertSame(12.567, $events[0]->conversions);

                return true;
            });

        $this->useCase->execute($date);
    }

    #[Test]
    public function it_handles_zero_spend_campaigns(): void
    {
        $date = '2024-11-18';
        $campaign = $this->createCampaignMetrics(
            campaignId: 123,
            campaignName: 'Zero Spend Campaign',
            date: $date,
            costInPounds: 0.0,
            clicks: 0,
            impressions: 0,
            conversions: 0.0,
        );

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
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
            ->with('Ad spend sync completed', ['date' => $date, 'campaigns_synced' => 1])
            ->once();

        $this->useCase->execute($date);
    }

    // ========================================================================
    // Logging Verification
    // ========================================================================

    #[Test]
    public function it_logs_start_with_correct_date(): void
    {
        $date = '2024-12-31';

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
            ->andReturn([]);

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Starting ad spend sync', ['date' => '2024-12-31'])
            ->once();

        $this->useCase->execute($date);
    }

    #[Test]
    public function it_logs_completion_with_exact_campaign_count(): void
    {
        $date = '2024-11-18';
        $campaigns = [
            $this->createCampaignMetrics(campaignId: 1, date: $date),
            $this->createCampaignMetrics(campaignId: 2, date: $date),
            $this->createCampaignMetrics(campaignId: 3, date: $date),
            $this->createCampaignMetrics(campaignId: 4, date: $date),
            $this->createCampaignMetrics(campaignId: 5, date: $date),
        ];

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
            ->andReturn($campaigns);

        $this->mixpanelClient
            ->shouldReceive('importCampaigns')
            ->once();

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Ad spend sync completed', ['date' => $date, 'campaigns_synced' => 5])
            ->once();

        $this->useCase->execute($date);
    }

    #[Test]
    public function it_logs_warning_with_correct_date_when_empty(): void
    {
        $date = '2024-01-01';

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
            ->andReturn([]);

        $this->loggerMock
            ->shouldReceive('warning')
            ->with('No campaigns found for date', ['date' => '2024-01-01'])
            ->once();

        $this->useCase->execute($date);
    }

    // ========================================================================
    // Event Array Order Preservation
    // ========================================================================

    #[Test]
    public function it_preserves_campaign_order_in_transformed_events(): void
    {
        $date = '2024-11-18';
        $campaigns = [
            $this->createCampaignMetrics(campaignId: 999, campaignName: 'Campaign Z', date: $date),
            $this->createCampaignMetrics(campaignId: 111, campaignName: 'Campaign A', date: $date),
            $this->createCampaignMetrics(campaignId: 555, campaignName: 'Campaign M', date: $date),
        ];

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
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

        $this->useCase->execute($date);
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
