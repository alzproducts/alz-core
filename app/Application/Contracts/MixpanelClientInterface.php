<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Domain\AdSpend\Enums\AdSource;
use App\Domain\AdSpend\ValueObjects\Campaign;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\PayloadSerializationException;

interface MixpanelClientInterface
{
    /**
     * Verify connectivity and authentication with Mixpanel API.
     *
     * Makes a lightweight API call to validate service account credentials
     * without modifying any data.
     *
     * @throws ExternalServiceUnavailableException When API unavailable or credentials invalid
     */
    public function verifyConnectivity(): void;

    /**
     * Import campaign metrics to Mixpanel analytics.
     *
     * Accepts Domain layer campaign metrics. Infrastructure implementation
     * handles internal transformation to Mixpanel event format.
     *
     * @param array<int, CampaignMetrics> $campaigns
     * @param AdSource $source The ad network these campaigns originate from
     *
     * @throws ExternalServiceUnavailableException When API unavailable or request fails
     * @throws PayloadSerializationException When payload cannot be encoded (data integrity issue)
     */
    public function importCampaigns(array $campaigns, AdSource $source): void;

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
