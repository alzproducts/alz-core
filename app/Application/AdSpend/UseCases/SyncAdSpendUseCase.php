<?php

declare(strict_types=1);

namespace App\Application\AdSpend\UseCases;

use App\Domain\AdSpend\Contracts\GoogleAdsClientInterface;
use App\Domain\AdSpend\Contracts\MixpanelClientInterface;
use App\Domain\AdSpend\Exceptions\ApiRateLimitException;
use App\Domain\AdSpend\Exceptions\GoogleAdsApiException;
use App\Domain\AdSpend\Exceptions\MixpanelApiException;
use App\Domain\AdSpend\Transformers\AdSpendTransformer;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrate ad spend synchronization from Google Ads to Mixpanel.
 *
 * Coordinates the complete workflow: fetch campaign metrics from Google Ads API,
 * transform to Mixpanel events, and import to Mixpanel.
 */
final readonly class SyncAdSpendUseCase
{
    public function __construct(
        private GoogleAdsClientInterface $googleAds,
        private MixpanelClientInterface $mixpanel,
    ) {}

    /**
     * Synchronize ad spend data for a specific date.
     *
     * @param string $date Date in YYYY-MM-DD format
     *
     * @throws GoogleAdsApiException
     * @throws ApiRateLimitException
     * @throws MixpanelApiException
     */
    public function execute(string $date): void
    {
        Log::info('Starting ad spend sync', ['date' => $date]);

        // Step 1: Fetch campaign metrics from Google Ads
        $campaigns = $this->googleAds->getDailyCampaignMetrics($date);

        // Step 2: Handle empty results
        if ($campaigns === []) {
            Log::warning('No campaigns found for date', ['date' => $date]);

            return;
        }

        // Step 3: Transform to Mixpanel events
        $events = AdSpendTransformer::transformToEvents($campaigns);

        // Step 4: Import to Mixpanel
        $this->mixpanel->importBatch($events);

        Log::info('Ad spend sync completed', [
            'date' => $date,
            'campaigns_synced' => \count($campaigns),
        ]);
    }
}
