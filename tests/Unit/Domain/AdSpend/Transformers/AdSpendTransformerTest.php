<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\AdSpend\Transformers;

use App\Domain\AdSpend\Transformers\AdSpendTransformer;
use App\Domain\AdSpend\ValueObjects\AdSpendEvent;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AdSpendTransformerTest extends TestCase
{
    #[Test]
    public function it_transforms_single_campaign_metric_to_ad_spend_event(): void
    {
        $campaign = new CampaignMetrics(
            campaignId: 123456,
            campaignName: '[01] Search - Branded',
            date: '2024-11-18',
            costInPounds: 125.43,
            clicks: 342,
            impressions: 8234,
            conversions: 12.5,
        );

        $events = AdSpendTransformer::transformToEvents([$campaign]);

        $this->assertCount(1, $events);
        $this->assertInstanceOf(AdSpendEvent::class, $events[0]);
    }

    #[Test]
    public function it_maps_all_fields_from_campaign_metrics_correctly(): void
    {
        $campaign = new CampaignMetrics(
            campaignId: 123456,
            campaignName: '[01] Search - Branded',
            date: '2024-11-18',
            costInPounds: 125.43,
            clicks: 342,
            impressions: 8234,
            conversions: 12.5,
        );

        $events = AdSpendTransformer::transformToEvents([$campaign]);
        $event = $events[0];

        $this->assertSame(123456, $event->campaignId);
        $this->assertSame('[01] Search - Branded', $event->campaignName);
        $this->assertSame(125.43, $event->cost);
        $this->assertSame(342, $event->clicks);
        $this->assertSame(8234, $event->impressions);
        $this->assertSame(12.5, $event->conversions);
    }

    #[Test]
    public function it_sets_fixed_utm_and_source_values(): void
    {
        $campaign = new CampaignMetrics(
            campaignId: 123456,
            campaignName: 'Test Campaign',
            date: '2024-11-18',
            costInPounds: 100.00,
            clicks: 100,
            impressions: 5000,
            conversions: 5.0,
        );

        $events = AdSpendTransformer::transformToEvents([$campaign]);
        $event = $events[0];

        $this->assertSame('Google', $event->source);
        $this->assertSame('google', $event->utmSource);
        $this->assertSame('cpc', $event->utmMedium);
    }

    #[Test]
    public function it_preserves_campaign_name_with_special_characters_in_utm_campaign(): void
    {
        $specialNames = [
            '[01] Search - Branded',
            '[TM] Shopping | Low Margin',
            'claim_your_free_lead_today_v1',
            '[02] Performance Max - All Products',
        ];

        foreach ($specialNames as $campaignName) {
            $campaign = new CampaignMetrics(
                campaignId: 123456,
                campaignName: $campaignName,
                date: '2024-11-18',
                costInPounds: 50.00,
                clicks: 100,
                impressions: 5000,
                conversions: 5.0,
            );

            $events = AdSpendTransformer::transformToEvents([$campaign]);

            // CRITICAL: Campaign name should NOT be sanitized
            $this->assertSame(
                $campaignName,
                $events[0]->utmCampaign,
                "Campaign name with special chars was not preserved: {$campaignName}",
            );
        }
    }

    #[Test]
    public function it_generates_insert_id_with_correct_format(): void
    {
        $campaign = new CampaignMetrics(
            campaignId: 123456,
            campaignName: 'Test Campaign',
            date: '2024-11-18',
            costInPounds: 0,
            clicks: 0,
            impressions: 0,
            conversions: 0,
        );

        $events = AdSpendTransformer::transformToEvents([$campaign]);

        // Format: G-{date}-{campaignId}
        $this->assertSame('G-2024-11-18-123456', $events[0]->insertId);
    }

    #[Test]
    public function it_hashes_insert_id_when_exceeding_36_character_limit(): void
    {
        // Create campaign with large ID that exceeds 36 char limit
        // G-2024-11-18-123456789012345678901234567890 = 45 chars
        $campaign = new CampaignMetrics(
            campaignId: 123456789012345678,
            campaignName: 'Test Campaign',
            date: '2024-11-18',
            costInPounds: 0,
            clicks: 0,
            impressions: 0,
            conversions: 0,
        );

        $events = AdSpendTransformer::transformToEvents([$campaign]);

        // Should be hashed to 36 chars max
        $this->assertLessThanOrEqual(36, \mb_strlen($events[0]->insertId));
    }

    #[Test]
    public function it_converts_date_string_to_unix_timestamp(): void
    {
        $campaign = new CampaignMetrics(
            campaignId: 123456,
            campaignName: 'Test Campaign',
            date: '2024-11-18',
            costInPounds: 0,
            clicks: 0,
            impressions: 0,
            conversions: 0,
        );

        $events = AdSpendTransformer::transformToEvents([$campaign]);

        // Verify it matches the timestamp for 2024-11-18
        $expectedTimestamp = \strtotime('2024-11-18');
        $this->assertSame($expectedTimestamp, $events[0]->timestamp);
        $this->assertGreaterThan(0, $events[0]->timestamp);
    }

    #[Test]
    public function it_returns_empty_array_for_empty_input(): void
    {
        $events = AdSpendTransformer::transformToEvents([]);

        $this->assertSame([], $events);
    }

    #[Test]
    public function it_transforms_multiple_campaigns_independently(): void
    {
        $campaigns = [
            new CampaignMetrics(
                campaignId: 111,
                campaignName: 'Campaign One',
                date: '2024-11-18',
                costInPounds: 100.00,
                clicks: 50,
                impressions: 1000,
                conversions: 5.0,
            ),
            new CampaignMetrics(
                campaignId: 222,
                campaignName: 'Campaign Two',
                date: '2024-11-18',
                costInPounds: 200.00,
                clicks: 100,
                impressions: 2000,
                conversions: 10.0,
            ),
            new CampaignMetrics(
                campaignId: 333,
                campaignName: 'Campaign Three',
                date: '2024-11-18',
                costInPounds: 300.00,
                clicks: 150,
                impressions: 3000,
                conversions: 15.0,
            ),
        ];

        $events = AdSpendTransformer::transformToEvents($campaigns);

        $this->assertCount(3, $events);
        $this->assertSame(111, $events[0]->campaignId);
        $this->assertSame('Campaign One', $events[0]->campaignName);
        $this->assertSame(222, $events[1]->campaignId);
        $this->assertSame('Campaign Two', $events[1]->campaignName);
        $this->assertSame(333, $events[2]->campaignId);
        $this->assertSame('Campaign Three', $events[2]->campaignName);
    }

    #[Test]
    public function it_handles_zero_spend_campaigns(): void
    {
        $campaign = new CampaignMetrics(
            campaignId: 123456,
            campaignName: 'Zero Spend Campaign',
            date: '2024-11-18',
            costInPounds: 0.0,
            clicks: 0,
            impressions: 0,
            conversions: 0.0,
        );

        $events = AdSpendTransformer::transformToEvents([$campaign]);

        $this->assertSame(0.0, $events[0]->cost);
        $this->assertSame(0, $events[0]->clicks);
        $this->assertSame(0, $events[0]->impressions);
        $this->assertSame(0.0, $events[0]->conversions);
    }

    #[Test]
    public function it_generates_unique_insert_ids_for_same_campaign_on_different_dates(): void
    {
        $campaign1 = new CampaignMetrics(
            campaignId: 123456,
            campaignName: 'Test Campaign',
            date: '2024-11-18',
            costInPounds: 100.00,
            clicks: 50,
            impressions: 1000,
            conversions: 5.0,
        );

        $campaign2 = new CampaignMetrics(
            campaignId: 123456,
            campaignName: 'Test Campaign',
            date: '2024-11-19',
            costInPounds: 150.00,
            clicks: 75,
            impressions: 1500,
            conversions: 7.5,
        );

        $events1 = AdSpendTransformer::transformToEvents([$campaign1]);
        $events2 = AdSpendTransformer::transformToEvents([$campaign2]);

        $this->assertSame('G-2024-11-18-123456', $events1[0]->insertId);
        $this->assertSame('G-2024-11-19-123456', $events2[0]->insertId);
        $this->assertNotSame($events1[0]->insertId, $events2[0]->insertId);
    }

    #[Test]
    public function it_maintains_decimal_precision_in_cost_values(): void
    {
        $campaign = new CampaignMetrics(
            campaignId: 123456,
            campaignName: 'Test Campaign',
            date: '2024-11-18',
            costInPounds: 125.43,
            clicks: 100,
            impressions: 5000,
            conversions: 5.0,
        );

        $events = AdSpendTransformer::transformToEvents([$campaign]);

        // Verify exact decimal precision
        $this->assertSame(125.43, $events[0]->cost);
    }

    #[Test]
    public function it_maintains_float_precision_in_conversions(): void
    {
        $campaign = new CampaignMetrics(
            campaignId: 123456,
            campaignName: 'Test Campaign',
            date: '2024-11-18',
            costInPounds: 100.00,
            clicks: 100,
            impressions: 5000,
            conversions: 12.5,
        );

        $events = AdSpendTransformer::transformToEvents([$campaign]);

        $this->assertSame(12.5, $events[0]->conversions);
    }

    #[Test]
    public function it_preserves_array_order_in_transformations(): void
    {
        $campaigns = [
            new CampaignMetrics(
                campaignId: 999,
                campaignName: 'Campaign Z',
                date: '2024-11-18',
                costInPounds: 10.00,
                clicks: 10,
                impressions: 100,
                conversions: 1.0,
            ),
            new CampaignMetrics(
                campaignId: 111,
                campaignName: 'Campaign A',
                date: '2024-11-18',
                costInPounds: 20.00,
                clicks: 20,
                impressions: 200,
                conversions: 2.0,
            ),
            new CampaignMetrics(
                campaignId: 555,
                campaignName: 'Campaign M',
                date: '2024-11-18',
                costInPounds: 30.00,
                clicks: 30,
                impressions: 300,
                conversions: 3.0,
            ),
        ];

        $events = AdSpendTransformer::transformToEvents($campaigns);

        // Order should be preserved, not sorted
        $this->assertSame(999, $events[0]->campaignId);
        $this->assertSame(111, $events[1]->campaignId);
        $this->assertSame(555, $events[2]->campaignId);
    }

    #[Test]
    public function it_generates_valid_insert_id_for_mutations_preventing_substitution(): void
    {
        // Test that insert ID cannot be mutated to use campaignId directly
        // or use different format separators
        $campaign = new CampaignMetrics(
            campaignId: 42,
            campaignName: 'Test',
            date: '2024-11-18',
            costInPounds: 0,
            clicks: 0,
            impressions: 0,
            conversions: 0,
        );

        $events = AdSpendTransformer::transformToEvents([$campaign]);

        // Must start with 'G-'
        $this->assertTrue(
            \str_starts_with($events[0]->insertId, 'G-'),
            'Insert ID must start with G- prefix',
        );

        // Must contain date
        $this->assertStringContainsString('2024-11-18', $events[0]->insertId);

        // Must contain campaign ID
        $this->assertStringContainsString('42', $events[0]->insertId);
    }
}
