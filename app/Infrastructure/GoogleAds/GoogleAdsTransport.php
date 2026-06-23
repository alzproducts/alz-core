<?php

declare(strict_types=1);

namespace App\Infrastructure\GoogleAds;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Infrastructure\Support\RetryAfterParser;
use App\Infrastructure\Support\TransientLogThrottle;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsClient as SdkGoogleAdsClient;
use Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest;
use Google\Ads\GoogleAds\V22\Services\UploadClickConversionsRequest;
use Google\Ads\GoogleAds\V22\Services\UploadClickConversionsResponse;
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

    private const string SERVICE_KEY = 'google-ads';

    public function __construct(
        private SdkGoogleAdsClient $sdkClient,
        private GoogleAdsConfig $config,
        private TransientLogThrottle $logThrottle,
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
     * Upload click conversions via the Google Ads ConversionUploadService.
     *
     * Per-conversion data faults surface via the `partial_failure_error` field on the
     * response (not as a thrown ApiException). We translate those to
     * {@see InvalidApiRequestException} so callers see them as request-level rejections.
     *
     * @throws ExternalServiceUnavailableException When API unavailable or rate limited
     * @throws AuthenticationExpiredException When credentials invalid or insufficient permissions
     * @throws InvalidApiRequestException When Google rejects the conversion data (partial failure)
     */
    public function uploadClickConversion(UploadClickConversionsRequest $request): void
    {
        try {
            $response = $this->sdkClient
                ->getConversionUploadServiceClient()
                ->uploadClickConversions($request);

            $this->handlePartialFailure($response);
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
        $this->logTransient(self::SERVICE_NAME . ' API error', [
            'code' => $e->getCode(),
            'error' => $e->getMessage(),
        ]);

        return new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logTransient(string $message, array $context): void
    {
        $window = $this->logThrottle->check(self::SERVICE_KEY);

        if ($window !== null) {
            Log::error($message, [...$context, 'note' => "Subsequent transient failures suppressed for {$window} minutes"]);
        } else {
            Log::warning($message, $context);
        }
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
     * Translate partial_failure_error on a successful response into a Domain exception.
     *
     * Google returns 2xx with `partial_failure_error` populated when individual conversions
     * inside an otherwise-valid request were rejected (e.g. expired gclid, unknown action ID).
     * This is a request-data fault, not a service-availability fault.
     *
     * @throws InvalidApiRequestException When at least one conversion was rejected
     */
    private function handlePartialFailure(UploadClickConversionsResponse $response): void
    {
        $error = $response->getPartialFailureError();

        if ($error === null || $error->getCode() === 0) {
            return;
        }

        Log::error(self::SERVICE_NAME . ' conversion upload partial failure', [
            'code' => $error->getCode(),
            'message' => $error->getMessage(),
        ]);

        throw new InvalidApiRequestException(self::SERVICE_NAME, $error->getMessage());
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
