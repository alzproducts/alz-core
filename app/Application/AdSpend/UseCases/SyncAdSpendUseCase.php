<?php

declare(strict_types=1);

namespace App\Application\AdSpend\UseCases;

use App\Application\Contracts\GoogleAdsClientInterface;
use App\Application\Contracts\MixpanelClientInterface;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

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
        private LoggerInterface $logger,
    ) {}

    /**
     * Synchronize ad spend data for a specific date.
     *
     * @param string $date Date in YYYY-MM-DD format
     *
     * @throws ExternalServiceUnavailableException
     * @throws InvalidArgumentException When date format is invalid
     */
    public function execute(string $date): void
    {
        // Validate date format before API calls
        if (\preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new InvalidArgumentException('Date must be in YYYY-MM-DD format.');
        }

        $this->logger->info('Starting ad spend sync', ['date' => $date]);

        // Step 1: Fetch campaign metrics from Google Ads
        $campaigns = $this->googleAds->getDailyCampaignMetrics($date);

        // Step 2: Handle empty results
        if ($campaigns === []) {
            $this->logger->warning('No campaigns found for date', ['date' => $date]);

            return;
        }

        // Step 3: Import campaign metrics to Mixpanel
        // Infrastructure layer handles internal transformation to Mixpanel event format
        $this->mixpanel->importCampaigns($campaigns);

        $this->logger->info('Ad spend sync completed', [
            'date' => $date,
            'campaigns_synced' => \count($campaigns),
        ]);
    }
}
