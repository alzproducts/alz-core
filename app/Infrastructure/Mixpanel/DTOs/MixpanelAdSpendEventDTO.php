<?php

declare(strict_types=1);

namespace App\Infrastructure\Mixpanel\DTOs;

use App\Domain\AdSpend\Enums\AdSource;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use Webmozart\Assert\Assert;

final readonly class MixpanelAdSpendEventDTO
{
    public function __construct(
        public string $insertId,
        public int $timestamp,
        public string $source,
        public int $campaignId,
        public string $campaignName,
        public float $cost,
        public int $clicks,
        public int $impressions,
        public float $conversions,
        public string $utmSource,
        public string $utmMedium,
        public string $utmCampaign,
    ) {
        Assert::notEmpty($insertId, 'Insert ID cannot be empty');
        // Validate character length (not byte length) for Mixpanel API $insert_id deduplication
        Assert::lessThanEq(\mb_strlen($insertId), 36, 'Insert ID must be ≤36 characters');
        Assert::greaterThan($timestamp, 0, 'Timestamp must be positive Unix time');
        Assert::notEmpty($source, 'Source cannot be empty');
    }

    /**
     * Transform campaign metrics from Domain into Mixpanel event DTO.
     *
     * Factory method that converts Domain layer CampaignMetrics into
     * Infrastructure-specific DTO for Mixpanel API formatting.
     *
     * @param CampaignMetrics $campaign Domain object with campaign metrics
     * @param AdSource $source The ad network these metrics originate from
     * @return self Infrastructure DTO ready for Mixpanel import
     */
    public static function fromCampaignMetrics(CampaignMetrics $campaign, AdSource $source): self
    {
        return new self(
            insertId: self::generateInsertId($campaign, $source),
            timestamp: (int) \strtotime($campaign->date . ' UTC'),
            source: $source->value,
            campaignId: $campaign->campaignId,
            campaignName: $campaign->campaignName,
            cost: $campaign->costInPounds,
            clicks: $campaign->clicks,
            impressions: $campaign->impressions,
            conversions: $campaign->conversions,
            utmSource: $source->utmSource(),
            utmMedium: 'cpc',
            utmCampaign: $campaign->campaignName,
        );
    }

    /**
     * Transform to Mixpanel's expected JSON structure with deduplication via $insert_id.
     *
     * @return array<string, mixed>
     */
    public function toMixpanelFormat(): array
    {
        return [
            'event' => 'Ad Data',
            'properties' => [
                'time' => $this->timestamp,
                'distinct_id' => '',
                '$insert_id' => $this->insertId,
                'source' => $this->source,
                'campaign_id' => $this->campaignId,
                'campaign_name' => $this->campaignName,
                'cost' => $this->cost,
                'clicks' => $this->clicks,
                'impressions' => $this->impressions,
                'conversions' => $this->conversions,
                'utm_source' => $this->utmSource,
                'utm_medium' => $this->utmMedium,
                'utm_campaign' => $this->utmCampaign,
            ],
        ];
    }

    /**
     * Generate deduplication ID for Mixpanel.
     *
     * Format: "{prefix}-{date}-{id}" (e.g., "G-2024-11-18-123456" for Google)
     * Hashed to 36 chars max if original exceeds limit.
     *
     * @return string Deduplication ID
     */
    private static function generateInsertId(CampaignMetrics $campaign, AdSource $source): string
    {
        $prefix = $source->prefix();
        $raw = "{$prefix}-{$campaign->date}-{$campaign->campaignId}";

        // Mixpanel $insert_id limit: 36 characters
        // Use SHA-256 (truncated to 32 chars) for deterministic ID generation
        if (\mb_strlen($raw) > 36) {
            return \mb_substr(\hash('sha256', $raw), 0, 32);
        }

        return $raw;
    }
}
