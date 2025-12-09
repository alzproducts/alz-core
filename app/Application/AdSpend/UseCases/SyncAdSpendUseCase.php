<?php

declare(strict_types=1);

namespace App\Application\AdSpend\UseCases;

use App\Application\Contracts\AdSpendClientInterface;
use App\Application\Contracts\MixpanelClientInterface;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Orchestrate ad spend synchronization from any ad source to Mixpanel.
 *
 * Uses Strategy pattern: works with any AdSpendClientInterface implementation
 * (Google Ads, Bing Ads, Facebook Ads) without modification.
 */
final readonly class SyncAdSpendUseCase
{
    public function __construct(
        private AdSpendClientInterface $adClient,
        private MixpanelClientInterface $mixpanel,
        private LoggerInterface $logger,
    ) {}

    /**
     * Synchronize ad spend data for a specific date.
     *
     * @throws ExternalServiceUnavailableException
     */
    public function execute(DateTimeImmutable $date): void
    {
        $dateString = $date->format('Y-m-d');
        $source = $this->adClient->getSource();

        $this->logger->info('Starting ad spend sync', [
            'date' => $dateString,
            'source' => $source->value,
        ]);

        // Step 1: Fetch campaign metrics from ad source
        $campaigns = $this->adClient->getDailyCampaignMetrics($dateString);

        // Step 2: Handle empty results
        if ($campaigns === []) {
            $this->logger->warning('No campaigns found for date', [
                'date' => $dateString,
                'source' => $source->value,
            ]);

            return;
        }

        // Step 3: Import campaign metrics to Mixpanel
        // Infrastructure layer handles internal transformation to Mixpanel event format
        $this->mixpanel->importCampaigns($campaigns, $source);

        $this->logger->info('Ad spend sync completed', [
            'date' => $dateString,
            'source' => $source->value,
            'campaigns_synced' => \count($campaigns),
        ]);
    }
}
