<?php

declare(strict_types=1);

namespace Tests\Feature\Application\AdSpend\UseCases;

use App\Application\AdSpend\UseCases\SyncAdSpendUseCase;
use App\Domain\AdSpend\Contracts\GoogleAdsClientInterface;
use App\Domain\AdSpend\Contracts\MixpanelClientInterface;
use App\Domain\AdSpend\Exceptions\ApiRateLimitException;
use App\Domain\AdSpend\Exceptions\GoogleAdsApiException;
use App\Domain\AdSpend\Exceptions\MixpanelApiException;
use App\Domain\AdSpend\Transformers\AdSpendTransformer;
use App\Domain\AdSpend\ValueObjects\AdSpendEvent;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(SyncAdSpendUseCase::class)]
final class SyncAdSpendUseCaseTest extends TestCase
{
    private GoogleAdsClientInterface&MockInterface $googleAdsClient;

    private MixpanelClientInterface&MockInterface $mixpanelClient;

    private SyncAdSpendUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->googleAdsClient = Mockery::mock(GoogleAdsClientInterface::class);
        $this->mixpanelClient = Mockery::mock(MixpanelClientInterface::class);

        $this->useCase = new SyncAdSpendUseCase(
            $this->googleAdsClient,
            $this->mixpanelClient,
        );
    }

    // ========================================================================
    // Happy Path Tests
    // ========================================================================

    #[Test]
    public function it_successfully_syncs_single_campaign(): void
    {
        Log::spy();

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
            ->shouldReceive('importBatch')
            ->once()
            ->withArgs(static function (array $events): bool {
                self::assertCount(1, $events);
                self::assertInstanceOf(AdSpendEvent::class, $events[0]);
                self::assertSame(123456, $events[0]->campaignId);
                self::assertSame('[01] Search - Branded', $events[0]->campaignName);
                self::assertSame(125.43, $events[0]->cost);
                self::assertSame(342, $events[0]->clicks);
                self::assertSame(8234, $events[0]->impressions);
                self::assertSame(12.5, $events[0]->conversions);
                self::assertSame('Google', $events[0]->source);
                self::assertSame('google', $events[0]->utmSource);
                self::assertSame('cpc', $events[0]->utmMedium);

                return true;
            });

        $this->useCase->execute($date);

        Log::shouldHaveReceived('info')
            ->with('Starting ad spend sync', ['date' => $date]);

        Log::shouldHaveReceived('info')
            ->with('Ad spend sync completed', ['date' => $date, 'campaigns_synced' => 1]);
    }

    #[Test]
    public function it_successfully_syncs_multiple_campaigns(): void
    {
        Log::spy();

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
            ->shouldReceive('importBatch')
            ->once()
            ->withArgs(static function (array $events): bool {
                self::assertCount(3, $events);
                self::assertSame(111, $events[0]->campaignId);
                self::assertSame('Campaign One', $events[0]->campaignName);
                self::assertSame(222, $events[1]->campaignId);
                self::assertSame('Campaign Two', $events[1]->campaignName);
                self::assertSame(333, $events[2]->campaignId);
                self::assertSame('Campaign Three', $events[2]->campaignName);

                return true;
            });

        $this->useCase->execute($date);

        Log::shouldHaveReceived('info')
            ->with('Ad spend sync completed', ['date' => $date, 'campaigns_synced' => 3]);
    }

    #[Test]
    public function it_passes_correct_date_to_google_ads_client(): void
    {
        Log::spy();

        $date = '2024-12-25';

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
            ->once()
            ->with($date)
            ->andReturn([]);

        $this->useCase->execute($date);

        // Verify date was passed correctly (empty result handled separately)
        Log::shouldHaveReceived('warning')
            ->with('No campaigns found for date', ['date' => $date]);
    }

    // ========================================================================
    // Empty Results Handling
    // ========================================================================

    #[Test]
    public function it_handles_empty_results_from_google_ads(): void
    {
        Log::spy();

        $date = '2024-11-18';

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
            ->once()
            ->with($date)
            ->andReturn([]);

        $this->mixpanelClient
            ->shouldNotReceive('importBatch');

        $this->useCase->execute($date);

        Log::shouldHaveReceived('info')
            ->with('Starting ad spend sync', ['date' => $date]);

        Log::shouldHaveReceived('warning')
            ->with('No campaigns found for date', ['date' => $date]);

        Log::shouldNotHaveReceived('info', static fn(string $message): bool => \str_contains($message, 'completed'));
    }

    #[Test]
    public function it_does_not_call_mixpanel_when_no_campaigns_found(): void
    {
        Log::spy();

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
    public function it_propagates_google_ads_api_exception(): void
    {
        Log::spy();

        $date = '2024-11-18';
        $exception = GoogleAdsApiException::fromApiError(
            'AUTH_ERROR',
            'The user does not have access.',
        );

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
    public function it_propagates_api_rate_limit_exception_from_google_ads(): void
    {
        Log::spy();

        $date = '2024-11-18';
        $exception = new ApiRateLimitException('Rate limit exceeded', 120);

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
            ->once()
            ->with($date)
            ->andThrow($exception);

        $this->mixpanelClient
            ->shouldNotReceive('importBatch');

        try {
            $this->useCase->execute($date);
            self::fail('Expected ApiRateLimitException to be thrown');
        } catch (ApiRateLimitException $e) {
            self::assertSame('Rate limit exceeded', $e->getMessage());
            self::assertSame(120, $e->getRetryAfter());
        }
    }

    #[Test]
    public function it_logs_start_before_google_ads_exception(): void
    {
        Log::spy();

        $date = '2024-11-18';
        $exception = GoogleAdsApiException::fromApiError('API_ERROR', 'Test error');

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
            ->andThrow($exception);

        try {
            $this->useCase->execute($date);
        } catch (GoogleAdsApiException) {
            // Expected
        }

        Log::shouldHaveReceived('info')
            ->with('Starting ad spend sync', ['date' => $date]);

        // Should not log completion on error
        Log::shouldNotHaveReceived('info', static fn(string $message): bool => \str_contains($message, 'completed'));
    }

    #[Test]
    public function it_does_not_log_completion_when_google_ads_fails(): void
    {
        Log::spy();

        $date = '2024-11-18';
        $exception = GoogleAdsApiException::fromApiError('QUERY_ERROR', 'Invalid query');

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
            ->andThrow($exception);

        try {
            $this->useCase->execute($date);
        } catch (GoogleAdsApiException) {
            // Expected
        }

        Log::shouldNotHaveReceived('info', static fn(string $message): bool => \str_contains($message, 'completed'));

        Log::shouldNotHaveReceived('warning');
    }

    // ========================================================================
    // Mixpanel Error Handling
    // ========================================================================

    #[Test]
    public function it_propagates_mixpanel_api_exception(): void
    {
        Log::spy();

        $date = '2024-11-18';
        $campaign = $this->createCampaignMetrics(campaignId: 123, date: $date);
        $exception = MixpanelApiException::fromValidationErrors([
            ['error' => 'invalid_payload'],
        ]);

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
            ->once()
            ->andReturn([$campaign]);

        $this->mixpanelClient
            ->shouldReceive('importBatch')
            ->once()
            ->andThrow($exception);

        $this->expectException(MixpanelApiException::class);
        $this->expectExceptionMessage('Mixpanel validation failed for 1 events');

        $this->useCase->execute($date);
    }

    #[Test]
    public function it_propagates_api_rate_limit_exception_from_mixpanel(): void
    {
        Log::spy();

        $date = '2024-11-18';
        $campaign = $this->createCampaignMetrics(campaignId: 123, date: $date);
        $exception = new ApiRateLimitException('Mixpanel rate limit exceeded', 30);

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
            ->once()
            ->andReturn([$campaign]);

        $this->mixpanelClient
            ->shouldReceive('importBatch')
            ->once()
            ->andThrow($exception);

        try {
            $this->useCase->execute($date);
            self::fail('Expected ApiRateLimitException to be thrown');
        } catch (ApiRateLimitException $e) {
            self::assertSame('Mixpanel rate limit exceeded', $e->getMessage());
            self::assertSame(30, $e->getRetryAfter());
        }
    }

    #[Test]
    public function it_does_not_log_completion_when_mixpanel_fails(): void
    {
        Log::spy();

        $date = '2024-11-18';
        $campaign = $this->createCampaignMetrics(campaignId: 123, date: $date);
        $exception = new MixpanelApiException('Import failed');

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
            ->andReturn([$campaign]);

        $this->mixpanelClient
            ->shouldReceive('importBatch')
            ->andThrow($exception);

        try {
            $this->useCase->execute($date);
        } catch (MixpanelApiException) {
            // Expected
        }

        Log::shouldHaveReceived('info')
            ->with('Starting ad spend sync', ['date' => $date]);

        Log::shouldNotHaveReceived('info', static fn(string $message): bool => \str_contains($message, 'completed'));
    }

    // ========================================================================
    // Data Transformation Validation
    // ========================================================================

    #[Test]
    public function it_transforms_campaign_metrics_to_events_correctly(): void
    {
        Log::spy();

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

        $expectedEvents = AdSpendTransformer::transformToEvents([$campaign]);

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
            ->andReturn([$campaign]);

        $this->mixpanelClient
            ->shouldReceive('importBatch')
            ->once()
            ->withArgs(static function (array $events) use ($expectedEvents): bool {
                self::assertCount(1, $events);

                $expected = $expectedEvents[0];
                $actual = $events[0];

                self::assertSame($expected->insertId, $actual->insertId);
                self::assertSame($expected->timestamp, $actual->timestamp);
                self::assertSame($expected->source, $actual->source);
                self::assertSame($expected->campaignId, $actual->campaignId);
                self::assertSame($expected->campaignName, $actual->campaignName);
                self::assertSame($expected->cost, $actual->cost);
                self::assertSame($expected->clicks, $actual->clicks);
                self::assertSame($expected->impressions, $actual->impressions);
                self::assertSame($expected->conversions, $actual->conversions);
                self::assertSame($expected->utmSource, $actual->utmSource);
                self::assertSame($expected->utmMedium, $actual->utmMedium);
                self::assertSame($expected->utmCampaign, $actual->utmCampaign);

                return true;
            });

        $this->useCase->execute($date);
    }

    #[Test]
    public function it_preserves_campaign_name_with_special_characters(): void
    {
        Log::spy();

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
            ->shouldReceive('importBatch')
            ->once()
            ->withArgs(static function (array $events) use ($specialCampaignName): bool {
                self::assertSame($specialCampaignName, $events[0]->campaignName);
                self::assertSame($specialCampaignName, $events[0]->utmCampaign);

                return true;
            });

        $this->useCase->execute($date);
    }

    #[Test]
    public function it_generates_correct_insert_id_format(): void
    {
        Log::spy();

        $date = '2024-11-18';
        $campaign = $this->createCampaignMetrics(campaignId: 123456, date: $date);

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
            ->andReturn([$campaign]);

        $this->mixpanelClient
            ->shouldReceive('importBatch')
            ->once()
            ->withArgs(static function (array $events): bool {
                self::assertSame('G-2024-11-18-123456', $events[0]->insertId);

                return true;
            });

        $this->useCase->execute($date);
    }

    #[Test]
    public function it_converts_date_to_unix_timestamp(): void
    {
        Log::spy();

        $date = '2024-11-18';
        $expectedTimestamp = (int) \strtotime($date);

        $campaign = $this->createCampaignMetrics(campaignId: 123, date: $date);

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
            ->andReturn([$campaign]);

        $this->mixpanelClient
            ->shouldReceive('importBatch')
            ->once()
            ->withArgs(static function (array $events) use ($expectedTimestamp): bool {
                self::assertSame($expectedTimestamp, $events[0]->timestamp);
                self::assertGreaterThan(0, $events[0]->timestamp);

                return true;
            });

        $this->useCase->execute($date);
    }

    #[Test]
    public function it_maintains_decimal_precision_in_cost_and_conversions(): void
    {
        Log::spy();

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
            ->shouldReceive('importBatch')
            ->once()
            ->withArgs(static function (array $events): bool {
                self::assertSame(125.43, $events[0]->cost);
                self::assertSame(12.567, $events[0]->conversions);

                return true;
            });

        $this->useCase->execute($date);
    }

    #[Test]
    public function it_handles_zero_spend_campaigns(): void
    {
        Log::spy();

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
            ->shouldReceive('importBatch')
            ->once()
            ->withArgs(static function (array $events): bool {
                self::assertSame(0.0, $events[0]->cost);
                self::assertSame(0, $events[0]->clicks);
                self::assertSame(0, $events[0]->impressions);
                self::assertSame(0.0, $events[0]->conversions);

                return true;
            });

        $this->useCase->execute($date);

        Log::shouldHaveReceived('info')
            ->with('Ad spend sync completed', ['date' => $date, 'campaigns_synced' => 1]);
    }

    // ========================================================================
    // Logging Verification
    // ========================================================================

    #[Test]
    public function it_logs_start_with_correct_date(): void
    {
        Log::spy();

        $date = '2024-12-31';

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
            ->andReturn([]);

        $this->useCase->execute($date);

        Log::shouldHaveReceived('info')
            ->with('Starting ad spend sync', ['date' => '2024-12-31']);
    }

    #[Test]
    public function it_logs_completion_with_exact_campaign_count(): void
    {
        Log::spy();

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
            ->shouldReceive('importBatch')
            ->once();

        $this->useCase->execute($date);

        Log::shouldHaveReceived('info')
            ->with('Ad spend sync completed', ['date' => $date, 'campaigns_synced' => 5]);
    }

    #[Test]
    public function it_logs_warning_with_correct_date_when_empty(): void
    {
        Log::spy();

        $date = '2024-01-01';

        $this->googleAdsClient
            ->shouldReceive('getDailyCampaignMetrics')
            ->andReturn([]);

        $this->useCase->execute($date);

        Log::shouldHaveReceived('warning')
            ->with('No campaigns found for date', ['date' => '2024-01-01']);
    }

    // ========================================================================
    // Event Array Order Preservation
    // ========================================================================

    #[Test]
    public function it_preserves_campaign_order_in_transformed_events(): void
    {
        Log::spy();

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
            ->shouldReceive('importBatch')
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
