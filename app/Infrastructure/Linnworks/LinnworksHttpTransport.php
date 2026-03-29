<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Infrastructure\Linnworks\Contracts\LinnworksTransportInterface;
use App\Infrastructure\Support\ApiRetryStrategy;
use Closure;
use DateMalformedStringException;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;

/**
 * HTTP transport layer for Linnworks API.
 *
 * Handles all HTTP concerns: session-based authentication, automatic 401 retry,
 * timeout configuration, and exception translation. This separation allows
 * endpoint clients to focus solely on business logic.
 *
 * Key differences from ShopWired transport:
 * - Session-based auth (not Basic Auth) via SessionManager
 * - Dynamic base URL from session (region-specific)
 * - Automatic 401 retry with transparent re-authentication (once)
 *
 * @template-pattern API Client HTTP Transport
 */
final readonly class LinnworksHttpTransport implements LinnworksTransportInterface
{
    private const string SERVICE_NAME = 'Linnworks';

    public function __construct(
        private LinnworksConfig $config,
        private LinnworksSessionManager $sessionManager,
    ) {}

    /**
     * Perform GET request to Linnworks API.
     *
     * @param string $endpoint API endpoint path (e.g., '/api/Inventory/GetInventoryItemById')
     * @param array<string, mixed> $query Query parameters
     *
     * @return Response Successful HTTP response
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws InvalidApiResponseException When session data is malformed (API contract violation)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable, rate limited, or connection fails
     */
    public function get(string $endpoint, array $query = []): Response
    {
        return $this->executeWithAuthRetry(
            // @phpstan-ignore missingType.checkedException, missingType.checkedException (closure exceptions caught in executeWithAuthRetry)
            fn(LinnworksSession $session): Response => $this->createBaseRequest($session, $endpoint)
                ->send('GET', $endpoint, ['query' => $query])
                ->throw(),
            $endpoint,
        );
    }

    /**
     * Perform POST request to Linnworks API.
     *
     * @param string $endpoint API endpoint path
     * @param array<string, mixed> $data Request body data (JSON-encoded and sent as form 'request' parameter)
     *
     * @return Response Successful HTTP response
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400) or data not serializable
     * @throws InvalidApiResponseException When session data is malformed (API contract violation)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable, rate limited, or connection fails
     */
    public function post(string $endpoint, array $data = []): Response
    {
        // Linnworks API expects form-encoded POST with 'request' containing JSON
        try {
            $formData = $data === [] ? [] : ['request' => \json_encode($data, \JSON_THROW_ON_ERROR)];
        } catch (JsonException $e) {
            // Programming error: caller passed unserializable data
            Log::error(self::SERVICE_NAME . ' failed to serialize request data', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            throw new InvalidApiRequestException(
                self::SERVICE_NAME,
                'Request data could not be serialized: ' . $e->getMessage(),
                $e,
            );
        }

        return $this->executeWithAuthRetry(
            // @phpstan-ignore missingType.checkedException, missingType.checkedException (closure exceptions caught in executeWithAuthRetry)
            fn(LinnworksSession $session): Response => $this->createBaseRequest($session, $endpoint)
                ->send('POST', $endpoint, ['form_params' => $formData])
                ->throw(),
            $endpoint,
        );
    }

    /**
     * Perform POST request with raw JSON body.
     *
     * Unlike post(), this sends JSON directly in the request body (not wrapped
     * in a 'request' form parameter). Used by Linnworks endpoints like
     * UpdateInventoryItemField that expect application/json content type.
     *
     * @param string $endpoint API endpoint path
     * @param array<string, mixed> $data Request body data (sent as JSON)
     *
     * @return Response Successful HTTP response
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws InvalidApiResponseException When session data is malformed (API contract violation)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable, rate limited, or connection fails
     */
    public function postJson(string $endpoint, array $data = []): Response
    {
        return $this->executeWithAuthRetry(
            // @phpstan-ignore missingType.checkedException, missingType.checkedException (closure exceptions caught in executeWithAuthRetry)
            fn(LinnworksSession $session): Response => $this->createBaseRequest($session, $endpoint)
                ->asJson()
                ->post($endpoint, $data)
                ->throw(),
            $endpoint,
        );
    }

    /**
     * Perform POST request with raw form-encoded parameters.
     *
     * Unlike post(), this sends parameters directly as form fields (not wrapped
     * in a 'request' JSON blob). Used by Linnworks endpoints like GetStockItemsFull
     * that expect query-string style parameters in the POST body.
     *
     * Array/object values are automatically JSON-encoded as string values.
     *
     * @param string $endpoint API endpoint path
     * @param array<string, scalar|array<mixed>|null> $params Form parameters (arrays will be JSON-encoded)
     *
     * @return Response Successful HTTP response
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws InvalidApiResponseException When session data is malformed (API contract violation)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable, rate limited, or connection fails
     */
    public function postFormParams(string $endpoint, array $params = []): Response
    {
        $formParams = LinnworksParamConverter::convertToFormParams($params, $endpoint);

        return $this->executeWithAuthRetry(
            // @phpstan-ignore missingType.checkedException, missingType.checkedException (closure exceptions caught in executeWithAuthRetry)
            fn(LinnworksSession $session): Response => $this->createBaseRequest($session, $endpoint)
                ->asForm()
                ->post($endpoint, $formParams)
                ->throw(),
            $endpoint,
        );
    }

    /**
     * Execute request with automatic 401 retry (once).
     *
     * On 401 response:
     * 1. Invalidate cached session
     * 2. Get fresh session (re-authenticate)
     * 3. Retry request once
     *
     * @param-immediately-invoked-callable $request
     * @param Closure(LinnworksSession): Response $request
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws InvalidApiResponseException When session data is malformed (API contract violation)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403 after retry)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    private function executeWithAuthRetry(Closure $request, string $endpoint): Response
    {
        try {
            $session = $this->sessionManager->getSession();
        } catch (DateMalformedStringException $e) {
            // Session data from Linnworks API has malformed date - API contract violation
            Log::critical(self::SERVICE_NAME . ' session contains malformed date', [
                'error' => $e->getMessage(),
            ]);

            throw new InvalidApiResponseException(
                self::SERVICE_NAME,
                'Session response contains malformed date: ' . $e->getMessage(),
                $e,
            );
        }

        try {
            return $request($session);
        } catch (RequestException $e) {
            if ($e->response->status() === 401) {
                // Invalidate and retry once
                $this->sessionManager->invalidate();

                try {
                    $session = $this->sessionManager->getSession();
                } catch (DateMalformedStringException $dateException) {
                    Log::critical(self::SERVICE_NAME . ' session contains malformed date after refresh', [
                        'error' => $dateException->getMessage(),
                    ]);

                    throw new InvalidApiResponseException(
                        self::SERVICE_NAME,
                        'Session response contains malformed date: ' . $dateException->getMessage(),
                        $dateException,
                    );
                }

                try {
                    return $request($session);
                } catch (RequestException $retryException) {
                    throw LinnworksErrorHandler::handleRequestException($retryException, $endpoint);
                }
            }

            throw LinnworksErrorHandler::handleRequestException($e, $endpoint);
        } catch (ConnectionException $e) {
            throw LinnworksErrorHandler::handleConnectionException($e);
        } catch (Exception $e) {
            throw LinnworksErrorHandler::handleUnexpectedException($e);
        }
    }

    /**
     * Create configured HTTP request for a session.
     *
     * Routes v2 endpoints to the v2 API host (eu-api) while legacy /api/
     * endpoints continue using the session's default server (eu-ext).
     *
     * @throws RuntimeException When HTTP client configuration fails
     */
    private function createBaseRequest(LinnworksSession $session, string $endpoint): PendingRequest
    {
        return Http::baseUrl(self::resolveBaseUrl($session->serverUrl, $endpoint))
            ->withHeaders(['Authorization' => $session->token])
            ->timeout($this->config->timeout)
            ->retry(3, 1000, ApiRetryStrategy::defaultRetry(), throw: false);
    }

    /**
     * Resolve the correct base URL based on endpoint prefix.
     *
     * Linnworks v2 endpoints live on a different host (eu-api.linnworks.net)
     * than legacy endpoints (eu-ext.linnworks.net). The session always returns
     * the legacy host, so we derive the v2 host by replacing the subdomain.
     */
    private static function resolveBaseUrl(string $serverUrl, string $endpoint): string
    {
        if (\str_starts_with($endpoint, '/v2/')) {
            return (string) \preg_replace('#://[^.]+\.#', '://eu-api.', $serverUrl);
        }

        return $serverUrl;
    }
}
