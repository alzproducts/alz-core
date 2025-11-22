<?php

declare(strict_types=1);

namespace App\Domain\AdSpend\Transformers;

use App\Domain\AdSpend\ValueObjects\AdSpendEvent;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;

/**
 * Transform campaign metrics into Mixpanel events.
 *
 * Converts Google Ads campaign data (CampaignMetrics) into a format suitable
 * for Mixpanel import, generating deduplication IDs and setting UTM parameters.
 */
final class AdSpendTransformer
{
    /**
     * Transform campaign metrics into Mixpanel ad spend events.
     *
     * @param array<int, CampaignMetrics> $campaigns
     * @return array<int, AdSpendEvent>
     */
    public static function transformToEvents(array $campaigns): array
    {
        return \array_map(
            static fn(CampaignMetrics $campaign): AdSpendEvent => AdSpendTransformer::transformSingle($campaign),
            $campaigns,
        );
    }

    /**
     * Transform a single campaign metric to an ad spend event.
     *
     * Uses campaign name directly without sanitization to ensure consistency
     * with Bing Ads and Google Ads click events (via {_cpname} custom param).
     */
    private static function transformSingle(CampaignMetrics $campaign): AdSpendEvent
    {
        return new AdSpendEvent(
            insertId: self::generateInsertId($campaign),
            timestamp: (int) \strtotime($campaign->date),
            source: 'Google',
            campaignId: $campaign->campaignId,
            campaignName: $campaign->campaignName,
            cost: $campaign->costInPounds,
            clicks: $campaign->clicks,
            impressions: $campaign->impressions,
            conversions: $campaign->conversions,
            utmSource: 'google',
            utmMedium: 'cpc',
            utmCampaign: $campaign->campaignName,
        );
    }

    /**
     * Generate deduplication ID for Mixpanel.
     *
     * Format: "G-{date}-{campaignId}" (e.g., "G-2024-11-18-123456")
     * Hashed to 36 chars max if original exceeds limit.
     */
    private static function generateInsertId(CampaignMetrics $campaign): string
    {
        $raw = "G-{$campaign->date}-{$campaign->campaignId}";

        // Mixpanel $insert_id limit: 36 characters
        if (\mb_strlen($raw) > 36) {
            return \mb_substr(\md5($raw), 0, 36);
        }

        return $raw;
    }

}
