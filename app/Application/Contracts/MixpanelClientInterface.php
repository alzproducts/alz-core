<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Domain\AdSpend\ValueObjects\Campaign;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Domain\Exceptions\ExternalServiceUnavailableException;

interface MixpanelClientInterface
{
    /**
     * Import campaign metrics to Mixpanel analytics.
     *
     * Accepts Domain layer campaign metrics. Infrastructure implementation
     * handles internal transformation to Mixpanel event format.
     *
     * @param array<int, CampaignMetrics> $campaigns
     *
     * @throws ExternalServiceUnavailableException
     */
    public function importCampaigns(array $campaigns): void;

    /**
     * Replace the campaign lookup table with latest campaign data.
     *
     * Syncs campaign ID→name mappings to Mixpanel Lookup Tables for UTM resolution.
     * Sends CSV with utm_campaign as join key:
     *
     * utm_campaign,campaign_name,campaign_status
     * 123456789,"[01] Search - Branded",ENABLED
     *
     * Note: Mixpanel only supports full replacement (PUT), not incremental updates.
     * Rate limit: 100 calls per 24 hours (hourly syncs recommended).
     *
     * @param array<int, Campaign> $campaigns All active campaigns from Google Ads
     *
     * @throws ExternalServiceUnavailableException
     */
    public function replaceCampaignLookupTable(array $campaigns): void;
}
