<?php

declare(strict_types=1);

namespace App\Application\AdSpend\UseCases;

use App\Application\Contracts\AdSpendClientInterface;
use App\Application\Contracts\MixpanelClientInterface;
use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiRequestException;
use App\Domain\Exceptions\PayloadSerializationException;
use App\Domain\ValueObjects\DateRange;
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
     * Synchronize ad spend data for a date range.
     *
     * @throws AuthenticationExpiredException When ad client or Mixpanel credentials invalid/expired
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws ExternalServiceUnavailableException When ad client or Mixpanel API unavailable
     * @throws PayloadSerializationException When Mixpanel payload cannot be encoded
     */
    public function execute(DateRange $dateRange): void
    {
        $source = $this->adClient->getSource();
        $fromDate = $dateRange->from->format('Y-m-d');
        $toDate = $dateRange->to->format('Y-m-d');

        $this->logger->info('Starting ad spend sync', [
            'from' => $fromDate,
            'to' => $toDate,
            'source' => $source->value,
        ]);

        // Step 1: Fetch campaign metrics from ad source
        $campaigns = $this->adClient->getCampaignMetricsByDateRange($dateRange);

        // Step 2: Handle empty results
        if ($campaigns === []) {
            $this->logger->warning('No campaigns found for date range', [
                'from' => $fromDate,
                'to' => $toDate,
                'source' => $source->value,
            ]);

            return;
        }

        // Step 3: Import campaign metrics to Mixpanel
        // Infrastructure layer handles internal transformation to Mixpanel event format
        $this->mixpanel->importCampaigns($campaigns, $source);

        $this->logger->info('Ad spend sync completed', [
            'from' => $fromDate,
            'to' => $toDate,
            'source' => $source->value,
            'campaigns_synced' => \count($campaigns),
        ]);
    }
}
