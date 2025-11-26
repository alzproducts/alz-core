<?php

declare(strict_types=1);

namespace App\Infrastructure\GoogleAds;

use App\Application\Contracts\GoogleAdsClientInterface;
use App\Domain\AdSpend\ValueObjects\Campaign;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiRequestException;
use App\Infrastructure\GoogleAds\Exceptions\InvalidGoogleAdsResponseException;
use App\Infrastructure\GoogleAds\Transformers\CampaignRowTransformer;
use App\Infrastructure\GoogleAds\Transformers\GoogleAdsRowTransformer;
use Google\Ads\GoogleAds\V22\Services\GoogleAdsRow;

/**
 * Google Ads API client for campaign data.
 *
 * Responsibilities:
 * 1. Construct GAQL queries for campaign data
 * 2. Transform SDK responses into domain value objects
 *
 * Design: Pure business logic - delegates all SDK interaction to GoogleAdsTransport.
 * Exception handling is done in the transport layer.
 *
 * @template-pattern API Client Business Logic
 */
final readonly class GoogleAdsClient implements GoogleAdsClientInterface
{
    public function __construct(
        private GoogleAdsTransport $transport,
    ) {}

    /**
     * Fetch daily campaign metrics for a specific date.
     *
     * @return list<CampaignMetrics>
     *
     * @throws ExternalServiceUnavailableException When API unavailable or rate limited
     * @throws InvalidApiRequestException When GAQL query is malformed
     * @throws InvalidGoogleAdsResponseException When response structure is invalid
     */
    public function getDailyCampaignMetrics(string $date): array
    {
        $query = <<<GAQL
            SELECT campaign.id,
                   campaign.name,
                   metrics.cost_micros,
                   metrics.clicks,
                   metrics.impressions,
                   metrics.conversions,
                   segments.date
            FROM campaign
            WHERE segments.date = '{$date}'
            GAQL;

        $response = $this->transport->search($query);

        $metrics = [];
        foreach ($response->iterateAllElements() as $item) {
            /** @var GoogleAdsRow $googleAdsRow */
            $googleAdsRow = $item;
            $metrics[] = GoogleAdsRowTransformer::toCampaignMetrics($googleAdsRow);
        }

        return $metrics;
    }

    /**
     * Fetch all active campaigns.
     *
     * @return list<Campaign>
     *
     * @throws ExternalServiceUnavailableException When API unavailable or rate limited
     * @throws InvalidApiRequestException When GAQL query is malformed
     * @throws InvalidGoogleAdsResponseException When response structure is invalid
     */
    public function getCampaigns(): array
    {
        $query = <<<GAQL
            SELECT campaign.id,
                   campaign.name,
                   campaign.status
            FROM campaign
            WHERE campaign.status != 'REMOVED'
            ORDER BY campaign.id
            GAQL;

        $response = $this->transport->search($query);

        $campaigns = [];
        foreach ($response->iterateAllElements() as $item) {
            /** @var GoogleAdsRow $googleAdsRow */
            $googleAdsRow = $item;
            $campaigns[] = CampaignRowTransformer::toCampaign($googleAdsRow);
        }

        return $campaigns;
    }
}
