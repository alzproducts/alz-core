<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks;

use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiRequestException;
use App\Domain\Exceptions\InvalidApiResponseException;
use App\Domain\Exceptions\ResourceNotFoundException;
use App\Infrastructure\Support\RetryAfterParser;
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
final readonly class LinnworksHttpTransport
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
            fn(LinnworksSession $session): Response => $this->createBaseRequest($session)
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
            $formData = ($data === []) ? [] : ['request' => \json_encode($data, \JSON_THROW_ON_ERROR)];
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
            fn(LinnworksSession $session): Response => $this->createBaseRequest($session)
                ->send('POST', $endpoint, ['form_params' => $formData])
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
                    throw $this->handleRequestException($retryException, $endpoint);
                }
            }

            throw $this->handleRequestException($e, $endpoint);
        } catch (ConnectionException $e) {
            throw $this->handleConnectionException($e);
        } catch (Exception $e) {
            throw $this->handleUnexpectedException($e);
        }
    }

    /**
     * Create configured HTTP request for a session.
     *
     * @throws RuntimeException When HTTP client configuration fails
     */
    private function createBaseRequest(LinnworksSession $session): PendingRequest
    {
        return Http::baseUrl($session->serverUrl)
            ->withHeaders(['Authorization' => $session->token])
            ->timeout($this->config->timeout);
    }

    /**
     * Route HTTP failures to specific handlers by status code.
     */
    private function handleRequestException(
        RequestException $e,
        string $endpoint,
    ): InvalidApiRequestException|AuthenticationExpiredException|ResourceNotFoundException|ExternalServiceUnavailableException {
        return match ($e->response->status()) {
            400 => $this->handleBadRequest($e),
            401, 403 => $this->handleAuthenticationFailure($e),
            404 => $this->handleNotFound($e, $endpoint),
            429 => $this->handleRateLimit($e),
            default => $this->handleServerError($e),
        };
    }

    /**
     * Handle 400 Bad Request (malformed request - programming error).
     */
    private function handleBadRequest(RequestException $e): InvalidApiRequestException
    {
        $message = $e->response->json('Message');

        Log::error(self::SERVICE_NAME . ' API invalid request', [
            'status' => 400,
            'error' => $e->getMessage(),
            'response_message' => \is_string($message) ? $message : 'No message provided',
        ]);

        return new InvalidApiRequestException(
            self::SERVICE_NAME,
            \is_string($message) ? $message : 'Invalid request parameters',
            $e,
        );
    }

    /**
     * Handle 401/403 authentication/authorization failures.
     */
    private function handleAuthenticationFailure(RequestException $e): AuthenticationExpiredException
    {
        $status = $e->response->status();

        Log::error(self::SERVICE_NAME . ' API authentication failed', [
            'status' => $status,
            'error' => $e->getMessage(),
        ]);

        return new AuthenticationExpiredException(
            self::SERVICE_NAME,
            ($status === 401) ? 'Invalid credentials' : 'Insufficient permissions',
            $e,
        );
    }

    /**
     * Handle 404 Not Found (resource doesn't exist - permanent).
     */
    private function handleNotFound(RequestException $e, string $endpoint): ResourceNotFoundException
    {
        Log::warning(self::SERVICE_NAME . ' API resource not found', [
            'endpoint' => $endpoint,
            'error' => $e->getMessage(),
        ]);

        return new ResourceNotFoundException(self::SERVICE_NAME, $endpoint, 'unknown');
    }

    /**
     * Handle 429 Rate Limit (transient - respect Retry-After).
     */
    private function handleRateLimit(RequestException $e): ExternalServiceUnavailableException
    {
        $retryAfter = RetryAfterParser::parse($e->response->header('Retry-After'));

        Log::warning(self::SERVICE_NAME . ' API rate limited', [
            'retry_after' => $retryAfter,
            'error' => $e->getMessage(),
        ]);

        return new ExternalServiceUnavailableException(self::SERVICE_NAME, $retryAfter, $e);
    }

    /**
     * Handle 5xx and other server errors (transient).
     */
    private function handleServerError(RequestException $e): ExternalServiceUnavailableException
    {
        Log::error(self::SERVICE_NAME . ' API request failed', [
            'status' => $e->response->status(),
            'error' => $e->getMessage(),
        ]);

        return new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
    }

    /**
     * Handle connection failures (network errors, timeouts).
     */
    private function handleConnectionException(ConnectionException $e): ExternalServiceUnavailableException
    {
        Log::error(self::SERVICE_NAME . ' API connection failed', [
            'error' => $e->getMessage(),
        ]);

        return new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
    }

    /**
     * Handle unexpected exceptions from Guzzle/Laravel internals.
     */
    private function handleUnexpectedException(Exception $e): ExternalServiceUnavailableException
    {
        Log::error(self::SERVICE_NAME . ' API unexpected error', [
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);

        return new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
    }
}
