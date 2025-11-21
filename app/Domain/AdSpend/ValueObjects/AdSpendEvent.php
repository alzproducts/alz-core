<?php

declare(strict_types=1);

namespace App\Domain\AdSpend\ValueObjects;

use Webmozart\Assert\Assert;

final readonly class AdSpendEvent
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
     * Convert to Mixpanel API format for import.
     *
     * Transforms event data into Mixpanel's expected JSON structure
     * with deduplication via $insert_id.
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
}
