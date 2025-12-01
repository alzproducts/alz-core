<?php

declare(strict_types=1);

namespace App\Domain\AdSpend\ValueObjects;

use Webmozart\Assert\Assert;

final readonly class CampaignMetrics
{
    public function __construct(
        public int $campaignId,
        public string $campaignName,
        public string $date,
        public float $costInPounds,
        public int $clicks,
        public int $impressions,
        public float $conversions,
    ) {
        Assert::greaterThan($campaignId, 0, 'Campaign ID must be positive');
        Assert::notEmpty($campaignName, 'Campaign name cannot be empty');
        Assert::regex($date, '/^\d{4}-\d{2}-\d{2}$/', 'Date must be YYYY-MM-DD format');
        Assert::greaterThanEq($costInPounds, 0, 'Cost cannot be negative');
        Assert::greaterThanEq($clicks, 0, 'Clicks cannot be negative');
        Assert::greaterThanEq($impressions, 0, 'Impressions cannot be negative');
        Assert::greaterThanEq($conversions, 0, 'Conversions cannot be negative');
    }
}
