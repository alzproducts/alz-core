<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Infrastructure\Shopwired\Contracts\ShopwiredTransportInterface;
use App\Infrastructure\Support\ApiRetryStrategy;
use App\Infrastructure\Support\RetryAfterParser;
use Closure;
use Exception;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * HTTP transport layer for Shopwired API.
 *
 * Handles all HTTP concerns: authentication, retry logic, timeout configuration,
 * and exception translation. This separation allows the client to focus solely
 * on business logic (validation, response parsing).
 *
 * Key responsibilities:
 * - Configure HTTP client with Basic Auth credentials
 * - Apply retry strategy for transient failures (when enabled)
 * - Translate HTTP exceptions to domain exceptions
 * - Log all failures with context before translation
 *
 * @template-pattern API Client HTTP Transport
 */
final readonly class ShopwiredHttpTransport implements ShopwiredTransportInterface
{
    private const string SERVICE_NAME = 'Shopwired';

    /**
     * Maximum backoff delay for exponential retry (16 seconds).
     */
    private const int MAX_BACKOFF_MS = 16000;

    public function __construct(
        private ShopwiredConfig $config,
    ) {}

    /**
     * Perform GET request to Shopwired API.
     *
     * @param string $endpoint API endpoint path (e.g., 'business')
     * @param array<string, mixed> $query Optional query parameters
     * @param bool $retry Whether to apply retry logic for transient failures
     * @param RetryStrategy $strategy Retry configuration (only used when $retry is true)
     *
     * @return Response Successful HTTP response
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable, rate limited, or connection fails
     */
    public function get(
        string $endpoint,
        array $query = [],
        bool $retry = true,
        RetryStrategy $strategy = RetryStrategy::Background,
    ): Response {
        try {
            return $this->createBaseRequest($retry, $strategy)
                ->send('GET', $endpoint, ['query' => $query])
                ->throw();
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, $endpoint);
        } catch (ConnectionException $e) {
            throw $this->handleConnectionException($e);
        } catch (Exception $e) {
            // Catch-all for unexpected exceptions from Guzzle/Laravel internals
            throw $this->handleUnexpectedException($e);
        }
    }

    /**
     * Perform POST request to Shopwired API.
     *
     * @param string $endpoint API endpoint path (e.g., 'orders/123/status')
     * @param array<mixed> $data Request body data (sent as JSON — accepts both maps and indexed lists)
     * @param bool $retry Whether to apply retry logic for transient failures
     * @param RetryStrategy $strategy Retry configuration (only used when $retry is true)
     *
     * @return Response Successful HTTP response
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable, rate limited, or connection fails
     */
    public function post(
        string $endpoint,
        array $data = [],
        bool $retry = true,
        RetryStrategy $strategy = RetryStrategy::Background,
    ): Response {
        try {
            return $this->createBaseRequest($retry, $strategy)
                ->send('POST', $endpoint, ['json' => $data])
                ->throw();
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, $endpoint);
        } catch (ConnectionException $e) {
            throw $this->handleConnectionException($e);
        } catch (Exception $e) {
            // Catch-all for unexpected exceptions from Guzzle/Laravel internals
            throw $this->handleUnexpectedException($e);
        }
    }

    /**
     * Perform PUT request to Shopwired API.
     *
     * @param string $endpoint API endpoint path (e.g., 'products/123')
     * @param array<string, mixed> $data Request body data (sent as JSON)
     * @param bool $retry Whether to apply retry logic for transient failures
     * @param RetryStrategy $strategy Retry configuration (only used when $retry is true)
     *
     * @return Response Successful HTTP response
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable, rate limited, or connection fails
     */
    public function put(
        string $endpoint,
        array $data = [],
        bool $retry = true,
        RetryStrategy $strategy = RetryStrategy::Background,
    ): Response {
        try {
            return $this->createBaseRequest($retry, $strategy)
                ->send('PUT', $endpoint, ['json' => $data])
                ->throw();
        } catch (RequestException $e) {
            throw $this->handleRequestException($e, $endpoint);
        } catch (ConnectionException $e) {
            throw $this->handleConnectionException($e);
        } catch (Exception $e) {
            // Catch-all for unexpected exceptions from Guzzle/Laravel internals
            throw $this->handleUnexpectedException($e);
        }
    }

    /**
     * Fetch a single resource by ID with proper 404 context.
     *
     * Use this for single-resource fetches (getOrderById, getCustomerById, etc.)
     * where 404 should throw ResourceNotFoundException with meaningful context.
     *
     * @param string $resourceType Resource type for exception context (e.g., 'Order', 'Customer')
     * @param int|string $id Resource ID
     * @param string $endpoint API endpoint path (e.g., 'orders')
     * @param array<string, mixed> $query Optional query parameters
     *
     * @return Response Successful HTTP response
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404) - with proper context
     * @throws ExternalServiceUnavailableException When API unavailable, rate limited, or connection fails
     */
    public function getResource(
        string $resourceType,
        int|string $id,
        string $endpoint,
        array $query = [],
    ): Response {
        try {
            return $this->get("{$endpoint}/{$id}", $query);
        } catch (ResourceNotFoundException $e) {
            // Re-throw with proper resource context instead of generic endpoint
            throw new ResourceNotFoundException(self::SERVICE_NAME, $resourceType, $id, $e);
        }
    }

    /**
     * Perform concurrent POST requests to Shopwired API.
     *
     * Uses Http::pool() for parallel execution of multiple POST requests.
     * Each request is configured with auth and retry logic. Returns keyed
     * Response array - caller handles validation of responses.
     *
     * @param array<string, array{endpoint: string, data: array<mixed>}> $requests Keyed array of endpoint/data pairs
     *
     * @return array<string, Response> Keyed responses matching input keys
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable, rate limited, or connection fails
     * @throws RuntimeException When HTTP pool initialization fails (Laravel/Guzzle internals)
     */
    public function poolPost(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        /**
         * Pool executes requests concurrently after closure returns.
         * Connection failures appear as Throwable in results array.
         *
         * @var array<string, Response|Throwable> $poolResults
         */
        $poolResults = Http::pool(fn(Pool $pool): array => $this->buildPoolRequests($pool, $requests));

        /** @var array<string, Response> $responses */
        $responses = [];

        foreach ($poolResults as $key => $result) {
            $responses[$key] = $this->handlePoolResult($key, $result, $requests);
        }

        return $responses;
    }

    /**
     * Handle a single pool result, translating failures to exceptions.
     *
     * @param array<string, array{endpoint: string, data: array<mixed>}> $requests Original requests for error context
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     */
    private function handlePoolResult(string $key, Response|Throwable $result, array $requests): Response
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
            if (!\array_key_exists($key, $requests)) {
                throw new LogicException('Pool result key must exist in original requests');
            }

            try {
                $result->throw();
            } catch (RequestException $e) {
                throw $this->handleRequestException($e, $requests[$key]['endpoint']);
            }
        }

        return $result;
    }

    /**
     * Build pool request definitions for concurrent execution.
     *
     * Note: Pool->post() returns PromiseInterface|Response at static type level.
     * After pool execution, these resolve to Response objects.
     *
     * @param array<string, array{endpoint: string, data: array<mixed>}> $requests
     *
     * @return array<string, PromiseInterface|Response>
     */
    private function buildPoolRequests(Pool $pool, array $requests): array
    {
        $poolRequests = [];

        foreach ($requests as $key => $request) {
            $poolRequests[$key] = $pool
                ->as($key)
                ->baseUrl($this->config->baseUrl)
                ->withBasicAuth($this->config->apiKey, $this->config->apiSecret)
                ->timeout($this->config->timeout)
                // @phpstan-ignore argument.type (data arrays always have int/string keys)
                ->post($request['endpoint'], $request['data']);
        }

        return $poolRequests;
    }

    /**
     * Create configured HTTP request with auth and optional retry logic.
     *
     * @throws RuntimeException When HTTP client configuration fails (caught by public methods)
     */
    private function createBaseRequest(bool $retry, RetryStrategy $strategy): PendingRequest
    {
        $request = Http::baseUrl($this->config->baseUrl)
            ->withBasicAuth(
                $this->config->apiKey,
                $this->config->apiSecret,
            )
            ->timeout($this->config->timeout);

        if ($retry) {
            $request = $request->retry(
                times: $strategy->times(),
                sleepMilliseconds: $this->buildSleepClosure($strategy),
                when: ApiRetryStrategy::defaultRetry(),
            );
        }

        return $request;
    }

    /**
     * Build sleep closure for retry delay calculation.
     *
     * @return Closure(int, mixed): int Sleep duration in milliseconds
     */
    private function buildSleepClosure(RetryStrategy $strategy): Closure
    {
        $baseMs = $strategy->baseDelayMs();

        if (! $strategy->useExponentialBackoff()) {
            return static fn(int $attempt, mixed $e): int => $baseMs;
        }

        // Exponential backoff: 500ms → 1s → 2s → 4s → 8s (capped at MAX_BACKOFF_MS)
        return static fn(int $attempt, mixed $e): int => (int) \min($baseMs * (2 ** ($attempt - 1)), self::MAX_BACKOFF_MS);
    }

    /**
     * Route HTTP failures to specific handlers by status code.
     *
     * @param string $endpoint The endpoint that was called (for 404 context)
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
            $status === 401 ? 'Invalid credentials' : 'Insufficient permissions',
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
