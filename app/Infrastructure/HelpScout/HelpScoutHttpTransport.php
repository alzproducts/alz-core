<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Infrastructure\Support\ApiRetryStrategy;
use App\Infrastructure\Support\RetryAfterParser;
use Exception;
use GuzzleHttp\Promise\PromiseInterface;
use HelpScout\Api\ApiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * HTTP transport layer for HelpScout API.
 *
 * Uses direct HTTP for API calls while leveraging the SDK's OAuth2
 * authenticator for token management. This approach provides full
 * field support (including `snooze`) that the SDK's entity hydration drops.
 *
 * @template-pattern API Client HTTP Transport
 */
final readonly class HelpScoutHttpTransport
{
    private const string SERVICE_NAME = 'HelpScout';

    public function __construct(
        private HelpScoutConfig $config,
        private ApiClient $sdkClient,
        private HttpFactory $httpFactory,
    ) {}

    /**
     * Perform GET request to HelpScout API.
     *
     * @param string $endpoint API endpoint (relative path, e.g., '/conversations')
     * @param array<string, mixed> $queryParams Query parameters
     *
     * @return Response Successful HTTP response
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable, rate limited, or connection fails
     */
    public function get(string $endpoint, array $queryParams = []): Response
    {
        try {
            return $this->createRequest()
                ->send('GET', HelpScoutConfig::BASE_URL . $endpoint, ['query' => $queryParams])
                ->throw();
        } catch (RequestException $e) {
            throw $this->handleRequestException($e);
        } catch (ConnectionException $e) {
            throw $this->handleConnectionException($e);
        } catch (Exception $e) {
            throw $this->handleUnexpectedException($e);
        }
    }

    /**
     * Perform concurrent GET requests to HelpScout API conversations endpoint.
     *
     * Uses Http::pool() for parallel execution - no serialization required.
     * Auth headers are fetched ONCE before the pool to avoid per-request
     * token refresh race conditions.
     *
     * @param array<string, array<string, mixed>> $requests Keyed array of query params
     *
     * @return array<string, Response> Keyed responses matching input keys
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable, rate limited, or connection fails
     */
    public function poolGet(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        // Get auth headers ONCE before pool to avoid per-request token refresh
        /** @var array<string, string> $authHeaders */
        $authHeaders = $this->sdkClient->getAuthenticator()->getAuthHeader();

        /**
         * Pool executes requests concurrently after closure returns.
         * Connection failures appear as ConnectionException|RequestException in results array.
         *
         * @var array<string, ConnectionException|RequestException|Response> $poolResults
         *
         * @phpstan-ignore staticMethod.dynamicCall, shipmonk.checkedExceptionInCallable
         */
        $poolResults = $this->httpFactory->pool(fn(Pool $pool): array => $this->buildPoolGetRequests($pool, $requests, $authHeaders));

        /** @var array<string, Response> $responses */
        $responses = [];

        foreach ($poolResults as $key => $result) {
            /** @phpstan-ignore cast.useless (PHP coerces numeric string keys to int at runtime) */
            $stringKey = (string) $key;
            $responses[$stringKey] = $this->handlePoolGetResult($stringKey, $result);
        }

        return $responses;
    }

    /**
     * Build pool GET request definitions for concurrent execution.
     *
     * @param array<string, array<string, mixed>> $requests Keyed query params
     * @param array<string, string> $authHeaders Pre-fetched OAuth2 headers
     *
     * @return array<string, PromiseInterface|Response>
     *
     * @throws ConnectionException Declared for PHPStan - not actually thrown during request building
     */
    private function buildPoolGetRequests(Pool $pool, array $requests, array $authHeaders): array
    {
        $poolRequests = [];

        foreach ($requests as $key => $queryParams) {
            /** @phpstan-ignore cast.useless (PHP coerces numeric string keys to int at runtime) */
            $stringKey = (string) $key;
            $poolRequests[$stringKey] = $pool
                ->as($stringKey)
                ->withHeaders($authHeaders)
                ->timeout($this->config->timeoutSeconds)
                ->get(HelpScoutConfig::BASE_URL . '/conversations', $queryParams);
        }

        return $poolRequests;
    }

    /**
     * Handle a single pool GET result, translating failures to exceptions.
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     */
    private function handlePoolGetResult(string $key, Response|Throwable $result): Response
    {
        if ($result instanceof Throwable) {
            Log::error(self::SERVICE_NAME . ' API pool request failed', [
                'key' => $key,
                'error' => $result->getMessage(),
            ]);

            if ($result instanceof ConnectionException) {
                throw $this->handleConnectionException($result);
            }

            throw new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $result);
        }

        if ($result->failed()) {
            try {
                $result->throw();
            } catch (RequestException $e) {
                throw $this->handleRequestException($e);
            }
        }

        return $result;
    }

    /**
     * Create configured HTTP request with auth and retry logic.
     *
     * Uses the SDK's authenticator to get the OAuth2 bearer token,
     * which handles token refresh automatically.
     *
     * @throws RuntimeException When SDK fails to provide auth header (token refresh failure)
     */
    private function createRequest(): PendingRequest
    {
        /** @var array<string, string> $authHeaders */
        $authHeaders = $this->sdkClient->getAuthenticator()->getAuthHeader();

        /**
         * Factory uses __call to proxy to PendingRequest, but IDE doesn't recognize this.
         *
         * @var PendingRequest $request
         *
         * @phpstan-ignore staticMethod.dynamicCall
         */
        $request = $this->httpFactory->withHeaders($authHeaders)
            ->retry(
                times: $this->config->retryAttempts,
                sleepMilliseconds: 100,
                when: ApiRetryStrategy::defaultRetry(),
            )
            ->timeout($this->config->timeoutSeconds);

        return $request;
    }

    /**
     * Route HTTP failures to specific handlers by status code.
     */
    private function handleRequestException(
        RequestException $e,
    ): InvalidApiRequestException|AuthenticationExpiredException|ExternalServiceUnavailableException {
        return match ($e->response->status()) {
            400 => $this->handleBadRequest($e),
            401, 403 => $this->handleAuthenticationFailure($e),
            429 => $this->handleRateLimit($e),
            default => $this->handleServerError($e),
        };
    }

    /**
     * Handle 400 Bad Request (malformed request - programming error).
     */
    private function handleBadRequest(RequestException $e): InvalidApiRequestException
    {
        Log::error(self::SERVICE_NAME . ' API invalid request', [
            'status' => 400,
            'error' => $e->getMessage(),
            'response' => $e->response->json(),
        ]);

        $message = $e->response->json('message');

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
     * Handle unexpected exceptions (Guzzle internals, retry edge cases).
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
