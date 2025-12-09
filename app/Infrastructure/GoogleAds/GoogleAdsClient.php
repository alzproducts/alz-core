<?php

declare(strict_types=1);

namespace App\Infrastructure\GoogleAds;

use App\Application\Contracts\GoogleAdsClientInterface;
use App\Domain\AdSpend\Enums\AdSource;
use App\Domain\AdSpend\ValueObjects\Campaign;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Infrastructure\GoogleAds\Exceptions\InvalidGoogleAdsResponseException;
use App\Infrastructure\GoogleAds\Transformers\CampaignRowTransformer;
use App\Infrastructure\GoogleAds\Transformers\GoogleAdsRowTransformer;
use Google\Ads\GoogleAds\V22\Services\GoogleAdsRow;
use Google\ApiCore\PagedListResponse;
use Google\ApiCore\ValidationException;
use Illuminate\Support\Facades\Log;

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

    public function getSource(): AdSource
    {
        return AdSource::Google;
    }

    /**
     * Verify connectivity and authentication with Google Ads API.
     *
     * Executes a minimal GAQL query (LIMIT 1) to validate OAuth credentials
     * and API access without fetching significant data.
     *
     * @throws ExternalServiceUnavailableException When API unavailable or credentials invalid
     */
    public function verifyConnectivity(): void
    {
        $query = <<<'GAQL'
            SELECT campaign.id
            FROM campaign
            LIMIT 1
            GAQL;

        $this->transport->search($query);
    }

    /**
     * Fetch daily campaign metrics for a specific date.
     *
     * @return list<CampaignMetrics>
     *
     * @throws ExternalServiceUnavailableException When API unavailable or rate limited
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

        return $this->transformRows($response, GoogleAdsRowTransformer::toCampaignMetrics(...));
    }

    /**
     * Fetch all active campaigns.
     *
     * @return list<Campaign>
     *
     * @throws ExternalServiceUnavailableException When API unavailable or rate limited
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

        return $this->transformRows($response, CampaignRowTransformer::toCampaign(...));
    }

    /**
     * Transform paginated response rows using the given transformer.
     *
     * Wraps iteration in try/catch to handle ValidationException that can be
     * thrown during pagination (e.g., invalid page tokens, serialization errors).
     *
     * @template T
     *
     * @param PagedListResponse                      $response    Paginated SDK response
     * @param callable(GoogleAdsRow): T              $transformer Row transformer function
     * @param-immediately-invoked-callable           $transformer
     *
     * @return list<T>
     *
     * @throws ExternalServiceUnavailableException When iteration fails
     * @throws InvalidGoogleAdsResponseException When row transformation fails
     */
    private function transformRows(PagedListResponse $response, callable $transformer): array
    {
        try {
            $results = [];
            foreach ($response->iterateAllElements() as $item) {
                /** @var GoogleAdsRow $row */
                $row = $item;
                $results[] = $transformer($row);
            }

            return $results;
        } catch (ValidationException $e) {
            Log::error('Google Ads response iteration failed', [
                'error' => $e->getMessage(),
            ]);

            throw new ExternalServiceUnavailableException('Google Ads', previous: $e);
        }
    }
}
