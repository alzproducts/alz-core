<?php

declare(strict_types=1);

namespace App\Infrastructure\AdSpend\GoogleAds;

use App\Domain\AdSpend\Contracts\GoogleAdsClientInterface;
use App\Domain\AdSpend\Exceptions\ApiRateLimitException;
use App\Domain\AdSpend\Exceptions\GoogleAdsApiException;
use App\Domain\AdSpend\Exceptions\InvalidGoogleAdsResponseException;
use App\Domain\AdSpend\ValueObjects\Campaign;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsClient as SdkGoogleAdsClient;
use Google\Ads\GoogleAds\V22\Services\GoogleAdsRow;
use Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest;
use Google\ApiCore\ApiException;
use Google\ApiCore\PagedListResponse;
use Google\ApiCore\ValidationException;
use Google\Rpc\Code;

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
 * - ApiException with RESOURCE_EXHAUSTED → ApiRateLimitException
 * - ApiException with other codes → GoogleAdsApiException
 * - InvalidGoogleAdsResponseException → passes through (raised by transformer)
 */
final readonly class GoogleAdsClient implements GoogleAdsClientInterface
{
    public function __construct(
        private SdkGoogleAdsClient $sdkClient,
        private string $customerId,
    ) {}

    /**
     * @return list<CampaignMetrics>
     * @throws GoogleAdsApiException
     * @throws ApiRateLimitException
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
     * @throws GoogleAdsApiException
     * @throws ApiRateLimitException
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
     * @throws GoogleAdsApiException
     * @throws ApiRateLimitException
     * @throws ValidationException
     */
    private function search(string $query): PagedListResponse
    {
        try {
            // Create search request
            $request = new SearchGoogleAdsRequest();
            $request->setCustomerId($this->customerId);
            $request->setQuery($query);
            $request->setPageSize(10000);

            // Execute query via Google Ads service client
            return $this->sdkClient->getGoogleAdsServiceClient()->search($request);
        } catch (ApiException $e) {
            // Handle API rate limiting
            if ($e->getCode() === Code::RESOURCE_EXHAUSTED) {
                // Extract retry-after from metadata if available
                $retryAfter = $this->extractRetryAfter($e);

                throw new ApiRateLimitException(
                    "Google Ads API rate limit exceeded: {$e->getMessage()}",
                    $retryAfter,
                    $e,
                );
            }

            // Handle other API errors
            throw GoogleAdsApiException::fromApiError(
                (string) $e->getCode(),
                $e->getMessage(),
                $e,
            );
        }
    }

    /**
     * Extract retry-after seconds from API exception metadata.
     *
     * Google Ads API includes retry-after information in response metadata
     * when rate limits are exceeded.
     */
    private function extractRetryAfter(ApiException $exception): int
    {
        // Default to 60 seconds if metadata is not available
        $retryAfter = 60;

        // Attempt to extract from exception metadata
        // Google Ads SDK may include this in the exception's underlying status
        $metadata = $exception->getMetadata();
        $retryAfterValue = $metadata['retry-after'] ?? null;
        if (\is_numeric($retryAfterValue)) {
            $extracted = (int) $retryAfterValue;
            if ($extracted > 0) {
                $retryAfter = $extracted;
            }
        }

        return $retryAfter;
    }
}
