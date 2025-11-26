<?php

declare(strict_types=1);

namespace App\Infrastructure\GoogleAds;

use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiRequestException;
use App\Infrastructure\Support\RetryAfterParser;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsClient as SdkGoogleAdsClient;
use Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest;
use Google\ApiCore\ApiException;
use Google\ApiCore\PagedListResponse;
use Google\ApiCore\ValidationException;
use Google\Rpc\Code;
use Illuminate\Support\Facades\Log;

/**
 * Transport layer for Google Ads SDK.
 *
 * Wraps the Google Ads SDK client and handles all exception translation.
 * This separation allows the client to focus solely on business logic
 * (GAQL query construction, response transformation).
 *
 * Key responsibilities:
 * - Execute GAQL queries via the SDK
 * - Translate SDK exceptions to domain exceptions
 * - Log failures with context before translation
 *
 * The SDK handles internally (we don't wrap):
 * - OAuth2 token refresh
 * - gRPC retry logic
 * - Connection pooling
 * - TLS negotiation
 *
 * @template-pattern API Client SDK Transport
 */
class GoogleAdsTransport
{
    private const string SERVICE_NAME = 'Google Ads';
    private const int PAGE_SIZE = 10000;

    public function __construct(
        private readonly SdkGoogleAdsClient $sdkClient,
        private readonly GoogleAdsConfig $config,
    ) {}

    /**
     * Execute a GAQL query against Google Ads API.
     *
     * @param string $query GAQL query to execute
     *
     * @return PagedListResponse Paginated response from the SDK
     *
     * @throws ExternalServiceUnavailableException When API unavailable or rate limited
     * @throws InvalidApiRequestException When request validation fails (programming error)
     */
    public function search(string $query): PagedListResponse
    {
        try {
            $request = $this->createSearchRequest($query);

            return $this->sdkClient->getGoogleAdsServiceClient()->search($request);
        } catch (ApiException $e) {
            throw $this->handleApiException($e);
        } catch (ValidationException $e) {
            throw $this->handleValidationException($e);
        }
    }

    /**
     * Create a search request with the configured customer ID.
     */
    private function createSearchRequest(string $query): SearchGoogleAdsRequest
    {
        $request = new SearchGoogleAdsRequest();
        $request->setCustomerId($this->config->customerId);
        $request->setQuery($query);
        $request->setPageSize(self::PAGE_SIZE);

        return $request;
    }

    /**
     * Handle API exceptions (rate limits, network errors, etc.).
     *
     * Rate limits (RESOURCE_EXHAUSTED) logged as WARNING (transient, recoverable).
     * Other errors logged as ERROR (unexpected failures).
     */
    private function handleApiException(ApiException $e): ExternalServiceUnavailableException
    {
        if ($e->getCode() === Code::RESOURCE_EXHAUSTED) {
            $retryAfter = $this->extractRetryAfter($e);

            Log::warning(self::SERVICE_NAME . ' API rate limited', [
                'retry_after' => $retryAfter,
                'error' => $e->getMessage(),
            ]);

            return new ExternalServiceUnavailableException(self::SERVICE_NAME, $retryAfter, $e);
        }

        Log::error(self::SERVICE_NAME . ' API error', [
            'code' => $e->getCode(),
            'error' => $e->getMessage(),
        ]);

        return new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
    }

    /**
     * Handle validation exceptions (malformed GAQL, invalid parameters).
     *
     * This indicates a programming error - our code constructed an invalid request.
     * Logged at CRITICAL level because code needs fixing.
     */
    private function handleValidationException(ValidationException $e): InvalidApiRequestException
    {
        Log::critical(self::SERVICE_NAME . ' API request validation failed', [
            'error' => $e->getMessage(),
        ]);

        return new InvalidApiRequestException(self::SERVICE_NAME, $e->getMessage(), $e);
    }

    /**
     * Extract retry-after value from exception metadata.
     */
    private function extractRetryAfter(ApiException $e): ?int
    {
        $metadata = $e->getMetadata();
        $retryAfterValue = $metadata['retry-after'] ?? null;

        return RetryAfterParser::parse(
            (\is_int($retryAfterValue) || \is_string($retryAfterValue)) ? $retryAfterValue : null,
        );
    }
}
