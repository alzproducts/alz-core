<?php

declare(strict_types=1);

namespace App\Application\AdSpend\UseCases;

use App\Application\Contracts\GoogleAdsClientInterface;
use App\Application\Contracts\MixpanelClientInterface;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use Psr\Log\LoggerInterface;

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
        private MixpanelClientInterface $mixpanel,
        private LoggerInterface $logger,
    ) {}

    /**
     * Synchronize campaign lookup table from Google Ads to Mixpanel.
     *
     * Fetches all active and paused campaigns from Google Ads and replaces the
     * Mixpanel Lookup Table with the complete dataset. This enables Mixpanel to
     * resolve campaign IDs (UTM parameters) to human-readable campaign names.
     *
     * @throws ExternalServiceUnavailableException
     */
    public function execute(): void
    {
        $this->logger->info('Starting campaign lookup table sync');

        // Step 1: Fetch campaigns from Google Ads
        $campaigns = $this->googleAds->getCampaigns();

        $this->logger->info('Retrieved campaigns from Google Ads', [
            'campaign_count' => \count($campaigns),
        ]);

        // Step 2: Handle empty results - this indicates an API issue or misconfiguration
        if ($campaigns === []) {
            $this->logger->error('No campaigns found in Google Ads - this may indicate an API issue or account misconfiguration');

            throw new ExternalServiceUnavailableException('Expected at least one campaign from Google Ads API, received empty result');
        }

        // Step 3: Upload to Mixpanel Lookup Table (replaces entire table)
        $this->mixpanel->replaceCampaignLookupTable($campaigns);

        $this->logger->info('Campaign lookup table sync completed', [
            'campaigns_synced' => \count($campaigns),
        ]);
    }
}
