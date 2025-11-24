<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\AdSpend\GoogleAds;

use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Infrastructure\GoogleAds\Exceptions\InvalidGoogleAdsResponseException;
use App\Infrastructure\GoogleAds\GoogleAdsRowTransformer;
use Google\Ads\GoogleAds\V22\Common\Metrics;
use Google\Ads\GoogleAds\V22\Common\Segments;
use Google\Ads\GoogleAds\V22\Resources\Campaign;
use Google\Ads\GoogleAds\V22\Services\GoogleAdsRow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * GoogleAdsRowTransformer Unit Tests
 *
 * Tests the critical validation boundary between the Google Ads SDK and Domain layer.
 * Ensures all null/missing fields and invalid types are caught before reaching the domain.
 */
#[CoversClass(GoogleAdsRowTransformer::class)]
final class GoogleAdsRowTransformerTest extends TestCase
{
    #[Test]
    public function it_transforms_a_valid_google_ads_row_to_campaign_metrics(): void
    {
        // Arrange
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getId')->willReturn(123456789);
        $campaign->method('getName')->willReturn('Test Campaign');

        $segments = $this->createMock(Segments::class);
        $segments->method('getDate')->willReturn('2024-05-10');

        $metrics = $this->createMock(Metrics::class);
        $metrics->method('getCostMicros')->willReturn(50_250_000); // 50.25 pounds
        $metrics->method('getClicks')->willReturn(150);
        $metrics->method('getImpressions')->willReturn(7500);
        $metrics->method('getConversions')->willReturn(10.5);

        $googleAdsRow = $this->createMock(GoogleAdsRow::class);
        $googleAdsRow->method('getCampaign')->willReturn($campaign);
        $googleAdsRow->method('getMetrics')->willReturn($metrics);
        $googleAdsRow->method('getSegments')->willReturn($segments);

        // Act
        $result = GoogleAdsRowTransformer::toCampaignMetrics($googleAdsRow);

        // Assert
        self::assertInstanceOf(CampaignMetrics::class, $result);
        self::assertSame(123456789, $result->campaignId);
        self::assertSame('Test Campaign', $result->campaignName);
        self::assertSame('2024-05-10', $result->date);
        self::assertSame(50.25, $result->costInPounds);
        self::assertSame(150, $result->clicks);
        self::assertSame(7500, $result->impressions);
        self::assertSame(10.5, $result->conversions);
    }

    #[Test]
    public function it_correctly_handles_zero_values_for_all_metrics(): void
    {
        // Arrange
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getId')->willReturn(1);
        $campaign->method('getName')->willReturn('Zero Metric Campaign');

        $segments = $this->createMock(Segments::class);
        $segments->method('getDate')->willReturn('2024-01-01');

        $metrics = $this->createMock(Metrics::class);
        $metrics->method('getCostMicros')->willReturn(0);
        $metrics->method('getClicks')->willReturn(0);
        $metrics->method('getImpressions')->willReturn(0);
        $metrics->method('getConversions')->willReturn(0.0);

        $googleAdsRow = $this->createMock(GoogleAdsRow::class);
        $googleAdsRow->method('getCampaign')->willReturn($campaign);
        $googleAdsRow->method('getMetrics')->willReturn($metrics);
        $googleAdsRow->method('getSegments')->willReturn($segments);

        // Act
        $result = GoogleAdsRowTransformer::toCampaignMetrics($googleAdsRow);

        // Assert
        self::assertSame(0.0, $result->costInPounds);
        self::assertSame(0, $result->clicks);
        self::assertSame(0, $result->impressions);
        self::assertSame(0.0, $result->conversions);
    }

    #[Test]
    public function it_handles_string_numeric_values_from_sdk_and_casts_them_correctly(): void
    {
        // Arrange
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getId')->willReturn('98765');
        $campaign->method('getName')->willReturn('String Numeric Campaign');

        $segments = $this->createMock(Segments::class);
        $segments->method('getDate')->willReturn('2024-05-11');

        $metrics = $this->createMock(Metrics::class);
        $metrics->method('getCostMicros')->willReturn('1000000'); // 1 pound
        $metrics->method('getClicks')->willReturn('50');
        $metrics->method('getImpressions')->willReturn('2000');
        $metrics->method('getConversions')->willReturn('2.5');

        $googleAdsRow = $this->createMock(GoogleAdsRow::class);
        $googleAdsRow->method('getCampaign')->willReturn($campaign);
        $googleAdsRow->method('getMetrics')->willReturn($metrics);
        $googleAdsRow->method('getSegments')->willReturn($segments);

        // Act
        $result = GoogleAdsRowTransformer::toCampaignMetrics($googleAdsRow);

        // Assert
        self::assertSame(98765, $result->campaignId);
        self::assertSame(1.0, $result->costInPounds);
        self::assertSame(50, $result->clicks);
        self::assertSame(2000, $result->impressions);
        self::assertSame(2.5, $result->conversions);
    }

    #[Test]
    public function it_throws_exception_if_campaign_object_is_missing(): void
    {
        // Arrange
        $googleAdsRow = $this->createMock(GoogleAdsRow::class);
        $googleAdsRow->method('getCampaign')->willReturn(null);

        // Assert
        $this->expectException(InvalidGoogleAdsResponseException::class);
        $this->expectExceptionMessage('Google Ads response missing required field: campaign (row.campaign)');

        // Act
        GoogleAdsRowTransformer::toCampaignMetrics($googleAdsRow);
    }

    #[Test]
    public function it_throws_exception_if_metrics_object_is_missing(): void
    {
        // Arrange
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getId')->willReturn(1);
        $campaign->method('getName')->willReturn('Test');

        $googleAdsRow = $this->createMock(GoogleAdsRow::class);
        $googleAdsRow->method('getCampaign')->willReturn($campaign);
        $googleAdsRow->method('getMetrics')->willReturn(null);

        // Assert
        $this->expectException(InvalidGoogleAdsResponseException::class);
        $this->expectExceptionMessage('Google Ads response missing required field: metrics (row.metrics)');

        // Act
        GoogleAdsRowTransformer::toCampaignMetrics($googleAdsRow);
    }

    #[Test]
    public function it_throws_exception_if_segments_object_is_missing(): void
    {
        // Arrange
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getId')->willReturn(1);
        $campaign->method('getName')->willReturn('Test');

        $metrics = $this->createMock(Metrics::class);

        $googleAdsRow = $this->createMock(GoogleAdsRow::class);
        $googleAdsRow->method('getCampaign')->willReturn($campaign);
        $googleAdsRow->method('getMetrics')->willReturn($metrics);
        $googleAdsRow->method('getSegments')->willReturn(null);

        // Assert
        $this->expectException(InvalidGoogleAdsResponseException::class);
        $this->expectExceptionMessage('Google Ads response missing required field: segments (row.segments)');

        // Act
        GoogleAdsRowTransformer::toCampaignMetrics($googleAdsRow);
    }

    #[Test]
    public function it_throws_exception_when_campaign_id_is_null(): void
    {
        // Arrange
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getId')->willReturn(null);
        $campaign->method('getName')->willReturn('Test');

        $segments = $this->createMock(Segments::class);
        $segments->method('getDate')->willReturn('2024-01-01');

        $metrics = $this->createMock(Metrics::class);
        $metrics->method('getCostMicros')->willReturn(100);
        $metrics->method('getClicks')->willReturn(10);
        $metrics->method('getImpressions')->willReturn(100);
        $metrics->method('getConversions')->willReturn(1.0);

        $googleAdsRow = $this->createMock(GoogleAdsRow::class);
        $googleAdsRow->method('getCampaign')->willReturn($campaign);
        $googleAdsRow->method('getMetrics')->willReturn($metrics);
        $googleAdsRow->method('getSegments')->willReturn($segments);

        // Assert
        $this->expectException(InvalidGoogleAdsResponseException::class);
        $this->expectExceptionMessage('Google Ads response missing required field: id (campaign.id)');

        // Act
        GoogleAdsRowTransformer::toCampaignMetrics($googleAdsRow);
    }

    #[Test]
    public function it_throws_exception_when_campaign_name_is_null(): void
    {
        // Arrange
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getId')->willReturn(1);
        $campaign->method('getName')->willReturn(null);

        $segments = $this->createMock(Segments::class);
        $segments->method('getDate')->willReturn('2024-01-01');

        $metrics = $this->createMock(Metrics::class);
        $metrics->method('getCostMicros')->willReturn(100);
        $metrics->method('getClicks')->willReturn(10);
        $metrics->method('getImpressions')->willReturn(100);
        $metrics->method('getConversions')->willReturn(1.0);

        $googleAdsRow = $this->createMock(GoogleAdsRow::class);
        $googleAdsRow->method('getCampaign')->willReturn($campaign);
        $googleAdsRow->method('getMetrics')->willReturn($metrics);
        $googleAdsRow->method('getSegments')->willReturn($segments);

        // Assert
        $this->expectException(InvalidGoogleAdsResponseException::class);
        $this->expectExceptionMessage('Google Ads response missing required field: name (campaign.name)');

        // Act
        GoogleAdsRowTransformer::toCampaignMetrics($googleAdsRow);
    }

    #[Test]
    public function it_throws_exception_when_date_is_null(): void
    {
        // Arrange
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getId')->willReturn(1);
        $campaign->method('getName')->willReturn('Test');

        $segments = $this->createMock(Segments::class);
        $segments->method('getDate')->willReturn(null);

        $metrics = $this->createMock(Metrics::class);
        $metrics->method('getCostMicros')->willReturn(100);
        $metrics->method('getClicks')->willReturn(10);
        $metrics->method('getImpressions')->willReturn(100);
        $metrics->method('getConversions')->willReturn(1.0);

        $googleAdsRow = $this->createMock(GoogleAdsRow::class);
        $googleAdsRow->method('getCampaign')->willReturn($campaign);
        $googleAdsRow->method('getMetrics')->willReturn($metrics);
        $googleAdsRow->method('getSegments')->willReturn($segments);

        // Assert
        $this->expectException(InvalidGoogleAdsResponseException::class);
        $this->expectExceptionMessage('Google Ads response missing required field: date (segments.date)');

        // Act
        GoogleAdsRowTransformer::toCampaignMetrics($googleAdsRow);
    }

    #[Test]
    public function it_throws_exception_when_cost_micros_is_null(): void
    {
        // Arrange
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getId')->willReturn(1);
        $campaign->method('getName')->willReturn('Test');

        $segments = $this->createMock(Segments::class);
        $segments->method('getDate')->willReturn('2024-01-01');

        $metrics = $this->createMock(Metrics::class);
        $metrics->method('getCostMicros')->willReturn(null);
        $metrics->method('getClicks')->willReturn(10);
        $metrics->method('getImpressions')->willReturn(100);
        $metrics->method('getConversions')->willReturn(1.0);

        $googleAdsRow = $this->createMock(GoogleAdsRow::class);
        $googleAdsRow->method('getCampaign')->willReturn($campaign);
        $googleAdsRow->method('getMetrics')->willReturn($metrics);
        $googleAdsRow->method('getSegments')->willReturn($segments);

        // Assert
        $this->expectException(InvalidGoogleAdsResponseException::class);
        $this->expectExceptionMessage('Google Ads response missing required field: cost_micros (metrics.cost_micros)');

        // Act
        GoogleAdsRowTransformer::toCampaignMetrics($googleAdsRow);
    }

    #[Test]
    public function it_throws_exception_when_clicks_is_null(): void
    {
        // Arrange
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getId')->willReturn(1);
        $campaign->method('getName')->willReturn('Test');

        $segments = $this->createMock(Segments::class);
        $segments->method('getDate')->willReturn('2024-01-01');

        $metrics = $this->createMock(Metrics::class);
        $metrics->method('getCostMicros')->willReturn(100);
        $metrics->method('getClicks')->willReturn(null);
        $metrics->method('getImpressions')->willReturn(100);
        $metrics->method('getConversions')->willReturn(1.0);

        $googleAdsRow = $this->createMock(GoogleAdsRow::class);
        $googleAdsRow->method('getCampaign')->willReturn($campaign);
        $googleAdsRow->method('getMetrics')->willReturn($metrics);
        $googleAdsRow->method('getSegments')->willReturn($segments);

        // Assert
        $this->expectException(InvalidGoogleAdsResponseException::class);
        $this->expectExceptionMessage('Google Ads response missing required field: clicks (metrics.clicks)');

        // Act
        GoogleAdsRowTransformer::toCampaignMetrics($googleAdsRow);
    }

    #[Test]
    public function it_throws_exception_when_impressions_is_null(): void
    {
        // Arrange
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getId')->willReturn(1);
        $campaign->method('getName')->willReturn('Test');

        $segments = $this->createMock(Segments::class);
        $segments->method('getDate')->willReturn('2024-01-01');

        $metrics = $this->createMock(Metrics::class);
        $metrics->method('getCostMicros')->willReturn(100);
        $metrics->method('getClicks')->willReturn(10);
        $metrics->method('getImpressions')->willReturn(null);
        $metrics->method('getConversions')->willReturn(1.0);

        $googleAdsRow = $this->createMock(GoogleAdsRow::class);
        $googleAdsRow->method('getCampaign')->willReturn($campaign);
        $googleAdsRow->method('getMetrics')->willReturn($metrics);
        $googleAdsRow->method('getSegments')->willReturn($segments);

        // Assert
        $this->expectException(InvalidGoogleAdsResponseException::class);
        $this->expectExceptionMessage('Google Ads response missing required field: impressions (metrics.impressions)');

        // Act
        GoogleAdsRowTransformer::toCampaignMetrics($googleAdsRow);
    }

    #[Test]
    public function it_throws_exception_when_conversions_is_null(): void
    {
        // Arrange
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getId')->willReturn(1);
        $campaign->method('getName')->willReturn('Test');

        $segments = $this->createMock(Segments::class);
        $segments->method('getDate')->willReturn('2024-01-01');

        $metrics = $this->createMock(Metrics::class);
        $metrics->method('getCostMicros')->willReturn(100);
        $metrics->method('getClicks')->willReturn(10);
        $metrics->method('getImpressions')->willReturn(100);
        $metrics->method('getConversions')->willReturn(null);

        $googleAdsRow = $this->createMock(GoogleAdsRow::class);
        $googleAdsRow->method('getCampaign')->willReturn($campaign);
        $googleAdsRow->method('getMetrics')->willReturn($metrics);
        $googleAdsRow->method('getSegments')->willReturn($segments);

        // Assert
        $this->expectException(InvalidGoogleAdsResponseException::class);
        $this->expectExceptionMessage('Google Ads response missing required field: conversions (metrics.conversions)');

        // Act
        GoogleAdsRowTransformer::toCampaignMetrics($googleAdsRow);
    }

    #[Test]
    public function it_throws_exception_when_campaign_id_has_invalid_type(): void
    {
        // Arrange
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getId')->willReturn(true);
        $campaign->method('getName')->willReturn('Test');

        $segments = $this->createMock(Segments::class);
        $segments->method('getDate')->willReturn('2024-01-01');

        $metrics = $this->createMock(Metrics::class);
        $metrics->method('getCostMicros')->willReturn(100);
        $metrics->method('getClicks')->willReturn(10);
        $metrics->method('getImpressions')->willReturn(100);
        $metrics->method('getConversions')->willReturn(1.0);

        $googleAdsRow = $this->createMock(GoogleAdsRow::class);
        $googleAdsRow->method('getCampaign')->willReturn($campaign);
        $googleAdsRow->method('getMetrics')->willReturn($metrics);
        $googleAdsRow->method('getSegments')->willReturn($segments);

        // Assert
        $this->expectException(InvalidGoogleAdsResponseException::class);
        $this->expectExceptionMessage('Google Ads response has invalid value for campaign.id: Expected int|string, got bool');

        // Act
        GoogleAdsRowTransformer::toCampaignMetrics($googleAdsRow);
    }

    #[Test]
    public function it_throws_exception_when_campaign_name_has_invalid_type(): void
    {
        // Arrange
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getId')->willReturn(1);
        $campaign->method('getName')->willReturn(123);

        $segments = $this->createMock(Segments::class);
        $segments->method('getDate')->willReturn('2024-01-01');

        $metrics = $this->createMock(Metrics::class);
        $metrics->method('getCostMicros')->willReturn(100);
        $metrics->method('getClicks')->willReturn(10);
        $metrics->method('getImpressions')->willReturn(100);
        $metrics->method('getConversions')->willReturn(1.0);

        $googleAdsRow = $this->createMock(GoogleAdsRow::class);
        $googleAdsRow->method('getCampaign')->willReturn($campaign);
        $googleAdsRow->method('getMetrics')->willReturn($metrics);
        $googleAdsRow->method('getSegments')->willReturn($segments);

        // Assert
        $this->expectException(InvalidGoogleAdsResponseException::class);
        $this->expectExceptionMessage('Google Ads response has invalid value for campaign.name: Expected string, got int');

        // Act
        GoogleAdsRowTransformer::toCampaignMetrics($googleAdsRow);
    }

    #[Test]
    public function it_throws_exception_when_date_has_invalid_type(): void
    {
        // Arrange
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getId')->willReturn(1);
        $campaign->method('getName')->willReturn('Test');

        $segments = $this->createMock(Segments::class);
        $segments->method('getDate')->willReturn([]);

        $metrics = $this->createMock(Metrics::class);
        $metrics->method('getCostMicros')->willReturn(100);
        $metrics->method('getClicks')->willReturn(10);
        $metrics->method('getImpressions')->willReturn(100);
        $metrics->method('getConversions')->willReturn(1.0);

        $googleAdsRow = $this->createMock(GoogleAdsRow::class);
        $googleAdsRow->method('getCampaign')->willReturn($campaign);
        $googleAdsRow->method('getMetrics')->willReturn($metrics);
        $googleAdsRow->method('getSegments')->willReturn($segments);

        // Assert
        $this->expectException(InvalidGoogleAdsResponseException::class);
        $this->expectExceptionMessage('Google Ads response has invalid value for segments.date: Expected string, got array');

        // Act
        GoogleAdsRowTransformer::toCampaignMetrics($googleAdsRow);
    }

    #[Test]
    public function it_throws_exception_when_cost_micros_has_invalid_type(): void
    {
        // Arrange
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getId')->willReturn(1);
        $campaign->method('getName')->willReturn('Test');

        $segments = $this->createMock(Segments::class);
        $segments->method('getDate')->willReturn('2024-01-01');

        $metrics = $this->createMock(Metrics::class);
        $metrics->method('getCostMicros')->willReturn([]);
        $metrics->method('getClicks')->willReturn(10);
        $metrics->method('getImpressions')->willReturn(100);
        $metrics->method('getConversions')->willReturn(1.0);

        $googleAdsRow = $this->createMock(GoogleAdsRow::class);
        $googleAdsRow->method('getCampaign')->willReturn($campaign);
        $googleAdsRow->method('getMetrics')->willReturn($metrics);
        $googleAdsRow->method('getSegments')->willReturn($segments);

        // Assert
        $this->expectException(InvalidGoogleAdsResponseException::class);
        $this->expectExceptionMessage('Google Ads response has invalid value for metrics.cost_micros: Expected int|string, got array');

        // Act
        GoogleAdsRowTransformer::toCampaignMetrics($googleAdsRow);
    }

    #[Test]
    public function it_throws_exception_when_clicks_has_invalid_type(): void
    {
        // Arrange
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getId')->willReturn(1);
        $campaign->method('getName')->willReturn('Test');

        $segments = $this->createMock(Segments::class);
        $segments->method('getDate')->willReturn('2024-01-01');

        $metrics = $this->createMock(Metrics::class);
        $metrics->method('getCostMicros')->willReturn(100);
        $metrics->method('getClicks')->willReturn(1.5);
        $metrics->method('getImpressions')->willReturn(100);
        $metrics->method('getConversions')->willReturn(1.0);

        $googleAdsRow = $this->createMock(GoogleAdsRow::class);
        $googleAdsRow->method('getCampaign')->willReturn($campaign);
        $googleAdsRow->method('getMetrics')->willReturn($metrics);
        $googleAdsRow->method('getSegments')->willReturn($segments);

        // Assert
        $this->expectException(InvalidGoogleAdsResponseException::class);
        $this->expectExceptionMessage('Google Ads response has invalid value for metrics.clicks: Expected int|string, got float');

        // Act
        GoogleAdsRowTransformer::toCampaignMetrics($googleAdsRow);
    }

    #[Test]
    public function it_throws_exception_when_impressions_has_invalid_type(): void
    {
        // Arrange
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getId')->willReturn(1);
        $campaign->method('getName')->willReturn('Test');

        $segments = $this->createMock(Segments::class);
        $segments->method('getDate')->willReturn('2024-01-01');

        $metrics = $this->createMock(Metrics::class);
        $metrics->method('getCostMicros')->willReturn(100);
        $metrics->method('getClicks')->willReturn(10);
        $metrics->method('getImpressions')->willReturn(false);
        $metrics->method('getConversions')->willReturn(1.0);

        $googleAdsRow = $this->createMock(GoogleAdsRow::class);
        $googleAdsRow->method('getCampaign')->willReturn($campaign);
        $googleAdsRow->method('getMetrics')->willReturn($metrics);
        $googleAdsRow->method('getSegments')->willReturn($segments);

        // Assert
        $this->expectException(InvalidGoogleAdsResponseException::class);
        $this->expectExceptionMessage('Google Ads response has invalid value for metrics.impressions: Expected int|string, got bool');

        // Act
        GoogleAdsRowTransformer::toCampaignMetrics($googleAdsRow);
    }

    #[Test]
    public function it_throws_exception_when_conversions_has_invalid_type(): void
    {
        // Arrange
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getId')->willReturn(1);
        $campaign->method('getName')->willReturn('Test');

        $segments = $this->createMock(Segments::class);
        $segments->method('getDate')->willReturn('2024-01-01');

        $metrics = $this->createMock(Metrics::class);
        $metrics->method('getCostMicros')->willReturn(100);
        $metrics->method('getClicks')->willReturn(10);
        $metrics->method('getImpressions')->willReturn(100);
        $metrics->method('getConversions')->willReturn(true);

        $googleAdsRow = $this->createMock(GoogleAdsRow::class);
        $googleAdsRow->method('getCampaign')->willReturn($campaign);
        $googleAdsRow->method('getMetrics')->willReturn($metrics);
        $googleAdsRow->method('getSegments')->willReturn($segments);

        // Assert
        $this->expectException(InvalidGoogleAdsResponseException::class);
        $this->expectExceptionMessage('Google Ads response has invalid value for metrics.conversions: Expected float|string, got bool');

        // Act
        GoogleAdsRowTransformer::toCampaignMetrics($googleAdsRow);
    }
}
