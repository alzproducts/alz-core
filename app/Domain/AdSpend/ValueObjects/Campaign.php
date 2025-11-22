<?php

declare(strict_types=1);

namespace App\Domain\AdSpend\ValueObjects;

use Webmozart\Assert\Assert;

final readonly class Campaign
{
    /**
     * Campaign metadata from Google Ads.
     *
     * @param int $campaignId Google Ads campaign ID
     * @param string $campaignName Human-readable campaign name
     * @param string $status Campaign status (ENABLED, PAUSED, REMOVED)
     */
    public function __construct(
        public int $campaignId,
        public string $campaignName,
        public string $status,
    ) {
        Assert::greaterThan($campaignId, 0, 'Campaign ID must be positive');
        Assert::notEmpty($campaignName, 'Campaign name cannot be empty');
        Assert::inArray($status, ['ENABLED', 'PAUSED', 'REMOVED'], 'Campaign status must be ENABLED, PAUSED, or REMOVED');
    }
}
