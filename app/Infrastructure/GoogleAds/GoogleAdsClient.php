<?php

declare(strict_types=1);

namespace App\Infrastructure\GoogleAds;

use App\Application\Contracts\GoogleAdsClientInterface;
use App\Domain\AdSpend\ValueObjects\Campaign;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Infrastructure\GoogleAds\Exceptions\InvalidGoogleAdsResponseException;
use App\Infrastructure\Support\RetryAfterParser;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsClient as SdkGoogleAdsClient;
use Google\Ads\GoogleAds\V22\Services\GoogleAdsRow;
use Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest;
use Google\ApiCore\ApiException;
use Google\ApiCore\PagedListResponse;
use Google\ApiCore\ValidationException;
use Google\Rpc\Code;
use Illuminate\Support\Facades\Log;

/**
 * Fetches daily campaign metrics from Google Ads API.
 *
 * Responsibilities:
 * 1. Query Google Ads API for campaign metrics using GAQL
 * 2. Transform SDK responses into domain value objects
 * 3. Handle API errors (rate limits, authentication failures)
 *
 * Design: Wraps the Google Ads SDK and delegates validation to GoogleAdsRowTransformer.
 * Error Handling:
 * - Catches SDK exceptions (GoogleAdsApiException, ApiException, etc.)
 * - Logs technical details with context
 * - Translates to Domain exception (ExternalServiceUnavailableException)
 * - Uses ApiRateLimitException internally for rate limit detection/logging context
 */
final readonly class GoogleAdsClient implements GoogleAdsClientInterface
{
    public function __construct(
        private SdkGoogleAdsClient $sdkClient,
        private string $customerId,
    ) {}

    /**
     * @return list<CampaignMetrics>
     * @throws ExternalServiceUnavailableException
     * @throws InvalidGoogleAdsResponseException|ValidationException
     */
    public function getDailyCampaignMetrics(string $date): array
    {
        // GAQL query: Fetch campaign metrics for a specific date
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

        // Execute query via helper method
        $response = $this->search($query);

        // Transform each row into CampaignMetrics domain value object
        $metrics = [];
        foreach ($response->iterateAllElements() as $item) {
            /** @var GoogleAdsRow $googleAdsRow */
            $googleAdsRow = $item;
            $metrics[] = GoogleAdsRowTransformer::toCampaignMetrics($googleAdsRow);
        }

        return $metrics;
    }

    /**
     * @return list<Campaign>
     *
     * @throws ExternalServiceUnavailableException
     * @throws InvalidGoogleAdsResponseException|ValidationException
     */
    public function getCampaigns(): array
    {
        // GAQL query: Fetch all campaigns (excluding removed)
        $query = <<<GAQL
            SELECT campaign.id,
                   campaign.name,
                   campaign.status
            FROM campaign
            WHERE campaign.status != 'REMOVED'
            ORDER BY campaign.id
            GAQL;

        // Execute query via helper method
        $response = $this->search($query);

        // Transform each row into Campaign domain value object
        $campaigns = [];
        foreach ($response->iterateAllElements() as $item) {
            /** @var GoogleAdsRow $googleAdsRow */
            $googleAdsRow = $item;
            $campaigns[] = CampaignRowTransformer::toCampaign($googleAdsRow);
        }

        return $campaigns;
    }

    /**
     * Execute a GAQL query against Google Ads API with unified error handling.
     *
     */
    private function search(string $query): PagedListResponse
    {
        try {
            $request = $this->createSearchRequest($query);

            // Execute query via Google Ads service client
            return $this->sdkClient->getGoogleAdsServiceClient()->search($request);
        } catch (ApiException $e) {
            // Detect rate limit and extract retryAfter if available
            $retryAfter = null;
            if ($e->getCode() === Code::RESOURCE_EXHAUSTED) {
                $metadata = $e->getMetadata();
                $retryAfterValue = $metadata['retry-after'] ?? null;
                $retryAfter = RetryAfterParser::parse(
                    (\is_int($retryAfterValue) || \is_string($retryAfterValue)) ? $retryAfterValue : null,
                );
                Log::warning('Google Ads rate limited', [
                    'retry_after' => $retryAfter,
                    'error' => $e->getMessage(),
                ]);
            } else {
                Log::error('Google Ads API error', [
                    'code' => $e->getCode(),
                    'error' => $e->getMessage(),
                ]);
            }

            // Translate to Domain exception with retryAfter if available
            throw new ExternalServiceUnavailableException('Google Ads', $retryAfter, $e);
        }
    }

    private function createSearchRequest(string $query): SearchGoogleAdsRequest
    {
        // Create search request
        $request = new SearchGoogleAdsRequest();
        $request->setCustomerId($this->customerId);
        $request->setQuery($query);
        $request->setPageSize(10000);

        return $request;
    }
}
