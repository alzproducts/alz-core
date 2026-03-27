<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\GoogleAds\Transformers;

use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Infrastructure\GoogleAds\Exceptions\InvalidGoogleAdsResponseException;
use App\Infrastructure\GoogleAds\Transformers\GoogleAdsRowTransformer;
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
 * Uses real protobuf objects to avoid segfaults from mock conflicts with protobuf C extension.
 *
 * Note: Protobuf scalar fields return default values (0, "") when unset, not null.
 * Only nested message fields (campaign, metrics, segments) can be null when unset.
 * Tests for null scalars and invalid types are removed as they're untestable with real protobuf.
 */
#[CoversClass(GoogleAdsRowTransformer::class)]
final class GoogleAdsRowTransformerTest extends TestCase
{
    #[Test]
    public function it_transforms_a_valid_google_ads_row_to_campaign_metrics(): void
    {
        $row = $this->createRealRow(
            campaignId: 123456789,
            campaignName: 'Test Campaign',
            date: '2024-05-10',
            costMicros: 50_250_000, // 50.25 pounds
            clicks: 150,
            impressions: 7500,
            conversions: 10.5,
        );

        $result = GoogleAdsRowTransformer::toCampaignMetrics($row);

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
        $row = $this->createRealRow(
            campaignId: 1,
            campaignName: 'Zero Metric Campaign',
            date: '2024-01-01',
            costMicros: 0,
            clicks: 0,
            impressions: 0,
            conversions: 0.0,
        );

        $result = GoogleAdsRowTransformer::toCampaignMetrics($row);

        self::assertSame(0.0, $result->costInPounds);
        self::assertSame(0, $result->clicks);
        self::assertSame(0, $result->impressions);
        self::assertSame(0.0, $result->conversions);
    }

    #[Test]
    public function it_handles_large_cost_values(): void
    {
        $row = $this->createRealRow(
            campaignId: 1,
            campaignName: 'High Spend Campaign',
            date: '2024-01-01',
            costMicros: 999_999_999_999, // ~999,999.99 pounds
            clicks: 1000000,
            impressions: 50000000,
            conversions: 15000.5,
        );

        $result = GoogleAdsRowTransformer::toCampaignMetrics($row);

        self::assertSame(999999.999999, $result->costInPounds);
        self::assertSame(1000000, $result->clicks);
        self::assertSame(50000000, $result->impressions);
        self::assertSame(15000.5, $result->conversions);
    }

    #[Test]
    public function it_throws_exception_if_campaign_object_is_missing(): void
    {
        // GoogleAdsRow without setCampaign() - getCampaign() returns null
        $row = new GoogleAdsRow();

        $this->expectException(InvalidGoogleAdsResponseException::class);
        $this->expectExceptionMessage('Invalid Google Ads API response');

        GoogleAdsRowTransformer::toCampaignMetrics($row);
    }

    #[Test]
    public function it_throws_exception_if_metrics_object_is_missing(): void
    {
        $campaign = new Campaign();
        $campaign->setId(1);
        $campaign->setName('Test');

        $row = new GoogleAdsRow();
        $row->setCampaign($campaign);
        // No setMetrics() call - getMetrics() returns null

        $this->expectException(InvalidGoogleAdsResponseException::class);
        $this->expectExceptionMessage('Invalid Google Ads API response');

        GoogleAdsRowTransformer::toCampaignMetrics($row);
    }

    #[Test]
    public function it_throws_exception_if_segments_object_is_missing(): void
    {
        $campaign = new Campaign();
        $campaign->setId(1);
        $campaign->setName('Test');

        $metrics = new Metrics();
        $metrics->setCostMicros(100);
        $metrics->setClicks(10);
        $metrics->setImpressions(100);
        $metrics->setConversions(1.0);

        $row = new GoogleAdsRow();
        $row->setCampaign($campaign);
        $row->setMetrics($metrics);
        // No setSegments() call - getSegments() returns null

        $this->expectException(InvalidGoogleAdsResponseException::class);
        $this->expectExceptionMessage('Invalid Google Ads API response');

        GoogleAdsRowTransformer::toCampaignMetrics($row);
    }

    /**
     * Helper method to create a real GoogleAdsRow with all required data.
     *
     * Uses actual protobuf objects instead of mocks to avoid segfaults
     * caused by PHPUnit mocking conflicts with the protobuf C extension.
     */
    private function createRealRow(
        int $campaignId,
        string $campaignName,
        string $date,
        int $costMicros,
        int $clicks,
        int $impressions,
        float $conversions,
    ): GoogleAdsRow {
        $campaign = new Campaign();
        $campaign->setId($campaignId);
        $campaign->setName($campaignName);

        $segments = new Segments();
        $segments->setDate($date);

        $metrics = new Metrics();
        $metrics->setCostMicros($costMicros);
        $metrics->setClicks($clicks);
        $metrics->setImpressions($impressions);
        $metrics->setConversions($conversions);

        $row = new GoogleAdsRow();
        $row->setCampaign($campaign);
        $row->setMetrics($metrics);
        $row->setSegments($segments);

        return $row;
    }
}
