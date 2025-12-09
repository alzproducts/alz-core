<?php

declare(strict_types=1);

namespace App\Infrastructure\GoogleAds;

use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Infrastructure\Support\RetryAfterParser;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsClient as SdkGoogleAdsClient;
use Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest;
use Google\ApiCore\ApiException;
use Google\ApiCore\PagedListResponse;
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
final readonly class GoogleAdsTransport
{
    private const string SERVICE_NAME = 'Google Ads';

    public function __construct(
        private SdkGoogleAdsClient $sdkClient,
        private GoogleAdsConfig $config,
    ) {}

    /**
     * Execute a GAQL query against Google Ads API.
     *
     * @param string $query GAQL query to execute
     *
     * @return PagedListResponse Paginated response from the SDK
     *
     * @throws ExternalServiceUnavailableException When API unavailable or rate limited
     * @throws AuthenticationExpiredException When credentials invalid or insufficient permissions
     */
    public function search(string $query): PagedListResponse
    {
        try {
            $request = $this->createSearchRequest($query);

            return $this->sdkClient->getGoogleAdsServiceClient()->search($request);
        } catch (ApiException $e) {
            throw $this->handleApiException($e);
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
        // Note: Page size is fixed at 10000 by the API; explicit setting not supported

        return $request;
    }

    /**
     * Route API failures to specific handlers by gRPC code.
     * Follows the same pattern as ShopwiredHttpTransport::handleRequestException()
     * but uses gRPC codes instead of HTTP status codes.
     *
     */
    private function handleApiException(ApiException $e): AuthenticationExpiredException|ExternalServiceUnavailableException
    {
        return match ($e->getCode()) {
            Code::RESOURCE_EXHAUSTED => $this->handleRateLimit($e),
            Code::PERMISSION_DENIED, Code::UNAUTHENTICATED => $this->handleAuthenticationFailure($e),
            default => $this->handleServerError($e),
        };
    }

    /**
     * Handle RESOURCE_EXHAUSTED (rate limit) - transient, respect Retry-After.
     */
    private function handleRateLimit(ApiException $e): ExternalServiceUnavailableException
    {
        $retryAfter = $this->extractRetryAfter($e);

        Log::warning(self::SERVICE_NAME . ' API rate limited', [
            'retry_after' => $retryAfter,
            'error' => $e->getMessage(),
        ]);

        return new ExternalServiceUnavailableException(self::SERVICE_NAME, $retryAfter, $e);
    }

    /**
     * Handle PERMISSION_DENIED/UNAUTHENTICATED - permanent, needs config fix.
     *
     */
    private function handleAuthenticationFailure(ApiException $e): AuthenticationExpiredException
    {
        $detailedMessage = $this->extractGoogleAdsErrorMessage($e);

        Log::error(self::SERVICE_NAME . ' API authentication failed', [
            'code' => $e->getCode(),
            'error' => $detailedMessage,
        ]);

        return new AuthenticationExpiredException(self::SERVICE_NAME, $detailedMessage, $e);
    }

    /**
     * Handle other API errors (INTERNAL, UNAVAILABLE, etc.) - transient.
     */
    private function handleServerError(ApiException $e): ExternalServiceUnavailableException
    {
        Log::error(self::SERVICE_NAME . ' API error', [
            'code' => $e->getCode(),
            'error' => $e->getMessage(),
        ]);

        return new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
    }

    /**
     * Extract specific error message from Google Ads API response.
     *
     * Google Ads errors are nested: message → details → errors[] → errorCode + message
     */
    private function extractGoogleAdsErrorMessage(ApiException $e): string
    {
        $decoded = \json_decode($e->getMessage(), true);

        if (! \is_array($decoded)) {
            return $e->getMessage();
        }

        // Use Laravel's data_get for safe nested access
        /** @var mixed $errorCode */
        $errorCode = \data_get($decoded, 'details.0.errors.0.errorCode', []);
        /** @var mixed $errorMessage */
        $errorMessage = \data_get($decoded, 'details.0.errors.0.message', '');

        // Extract specific code from errorCode map (e.g., {'authorizationError': 'CODE'})
        $specificCode = 'UNKNOWN';
        if (\is_array($errorCode) && ($errorCode !== [])) {
            $firstValue = \reset($errorCode);
            if (\is_string($firstValue)) {
                $specificCode = $firstValue;
            }
        }

        // Ensure errorMessage is string
        $errorMessage = \is_string($errorMessage) ? $errorMessage : '';

        if ($errorMessage !== '') {
            return "{$specificCode} - {$errorMessage}";
        }

        // Fallback to top-level message
        /** @var mixed $topMessage */
        $topMessage = \data_get($decoded, 'message', '');

        if (\is_string($topMessage) && ($topMessage !== '')) {
            return $topMessage;
        }

        return $e->getMessage();
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
