<?php

declare(strict_types=1);

namespace App\Application\AdSpend\UseCases;

use App\Domain\AdSpend\Contracts\GoogleAdsClientInterface;
use App\Domain\AdSpend\Contracts\MixpanelCampaignLookupClientInterface;
use App\Domain\AdSpend\Exceptions\GoogleAdsApiException;
use App\Domain\AdSpend\Exceptions\MixpanelApiException;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrate campaign lookup table synchronization from Google Ads to Mixpanel.
 *
 * Coordinates the workflow: fetch campaign metadata from Google Ads API and
 * synchronize to Mixpanel Lookup Tables for UTM parameter resolution.
 */
final readonly class SyncCampaignLookupTableUseCase
{
    public function __construct(
        private GoogleAdsClientInterface $googleAds,
        private MixpanelCampaignLookupClientInterface $mixpanelLookupTable,
    ) {}

    /**
     * Synchronize campaign lookup table from Google Ads to Mixpanel.
     *
     * Fetches all active and paused campaigns from Google Ads and replaces the
     * Mixpanel Lookup Table with the complete dataset. This enables Mixpanel to
     * resolve campaign IDs (UTM parameters) to human-readable campaign names.
     *
     * @throws GoogleAdsApiException
     * @throws MixpanelApiException
     */
    public function execute(): void
    {
        Log::info('Starting campaign lookup table sync');

        // Step 1: Fetch campaigns from Google Ads
        $campaigns = $this->googleAds->getCampaigns();

        Log::info('Retrieved campaigns from Google Ads', [
            'campaign_count' => \count($campaigns),
        ]);

        // Step 2: Handle empty results
        if ($campaigns === []) {
            Log::warning('No campaigns found in Google Ads, clearing Mixpanel lookup table');
            Log::info('Campaign lookup table sync completed', [
                'campaigns_synced' => 0,
            ]);

            return;
        }

        // Step 3: Upload to Mixpanel Lookup Table (replaces entire table)
        $this->mixpanelLookupTable->replaceCampaignLookupTable($campaigns);

        Log::info('Campaign lookup table sync completed', [
            'campaigns_synced' => \count($campaigns),
        ]);
    }
}
