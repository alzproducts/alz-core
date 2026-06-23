<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired;

use App\Domain\Exceptions\Api\AbstractApiException;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Infrastructure\Shopwired\Contracts\ShopwiredTransportInterface;
use App\Infrastructure\Support\ApiRetryStrategy;
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
        private ShopwiredErrorHandler $errorHandler,
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
     * @throws ResourceNotAvailableException When resource not found (404) - treated as transient (consistency lag)
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
            throw $this->errorHandler->handleRequestException($e, $endpoint);
        } catch (ConnectionException $e) {
            throw $this->errorHandler->handleConnectionException($e);
        } catch (Exception $e) {
            // Catch-all for unexpected exceptions from Guzzle/Laravel internals
            throw $this->errorHandler->handleUnexpectedException($e);
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
     * @throws ResourceNotAvailableException When resource not found (404) - treated as transient (consistency lag)
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
            throw $this->errorHandler->handleRequestException($e, $endpoint);
        } catch (ConnectionException $e) {
            throw $this->errorHandler->handleConnectionException($e);
        } catch (Exception $e) {
            // Catch-all for unexpected exceptions from Guzzle/Laravel internals
            throw $this->errorHandler->handleUnexpectedException($e);
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
     * @throws ResourceNotAvailableException When resource not found (404) - treated as transient (consistency lag)
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
            throw $this->errorHandler->handleRequestException($e, $endpoint);
        } catch (ConnectionException $e) {
            throw $this->errorHandler->handleConnectionException($e);
        } catch (Exception $e) {
            // Catch-all for unexpected exceptions from Guzzle/Laravel internals
            throw $this->errorHandler->handleUnexpectedException($e);
        }
    }

    /**
     * Fetch a single resource by ID with proper 404 context.
     *
     * Use this for single-resource fetches (getOrderById, getCustomerById, etc.)
     * where 404 should throw ResourceNotAvailableException with meaningful context.
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
     * @throws ResourceNotAvailableException When resource not found (404) - treated as transient (consistency lag) - with proper context
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
        } catch (ResourceNotAvailableException $e) {
            // Re-throw with proper resource context instead of generic endpoint
            throw new ResourceNotAvailableException(self::SERVICE_NAME, $resourceType, $id, retryAfter: 30, previous: $e);
        }
    }

    /**
     * Perform concurrent POST requests to Shopwired API.
     *
     * Uses Http::pool() for parallel execution of multiple POST requests.
     * Each request is configured with auth, timeout, and Background retry logic.
     *
     * Individual batch transport failures (AbstractApiException) are captured in the
     * result rather than thrown immediately, allowing callers to process partial
     * successes. Non-transport exceptions (LogicException) still propagate immediately.
     *
     * @param array<string, array{endpoint: string, data: array<mixed>}> $requests Keyed array of endpoint/data pairs
     *
     * @throws ExternalServiceUnavailableException When HTTP pool initialization fails (Laravel/Guzzle internals)
     */
    public function poolPost(array $requests): PoolPostResult
    {
        if ($requests === []) {
            return new PoolPostResult([]);
        }

        try {
            /**
             * Pool executes requests concurrently after closure returns.
             * Connection failures appear as Throwable in results array.
             *
             * @var array<string, Response|Throwable> $poolResults
             */
            $poolResults = Http::pool(fn(Pool $pool): array => $this->buildPoolRequests($pool, $requests));
        } catch (RuntimeException $e) {
            throw $this->errorHandler->handleUnexpectedException($e);
        }

        /** @var array<string, Response> $responses */
        $responses = [];
        /** @var list<AbstractApiException> $failures */
        $failures = [];

        foreach ($poolResults as $key => $result) {
            try {
                $responses[$key] = $this->handlePoolResult($key, $result, $requests);
            } catch (AbstractApiException $e) {
                // handlePoolResult already logged the failure with context.
                // Collect all failures; callers decide how to handle.
                $failures[] = $e;
            }
        }

        return new PoolPostResult($responses, $failures);
    }

    /**
     * Handle a single pool result, translating failures to exceptions.
     *
     * @param array<string, array{endpoint: string, data: array<mixed>}> $requests Original requests for error context
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When resource not found (404) - treated as transient (consistency lag)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     */
    private function handlePoolResult(string $key, Response|Throwable $result, array $requests): Response
    {
        if ($result instanceof Throwable) {
            Log::error(self::SERVICE_NAME . ' API pool request failed', [
                'key' => $key,
                'error' => $result->getMessage(),
                'exception_class' => $result::class,
            ]);

            if ($result instanceof ConnectionException) {
                throw $this->errorHandler->handleConnectionException($result);
            }

            if ($result instanceof RequestException) {
                throw $this->errorHandler->handleRequestException($result, $requests[$key]['endpoint'] ?? 'unknown');
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
                throw $this->errorHandler->handleRequestException($e, $requests[$key]['endpoint']);
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
                ->retry(
                    times: RetryStrategy::Background->times(),
                    sleepMilliseconds: $this->buildSleepClosure(RetryStrategy::Background),
                    when: ApiRetryStrategy::defaultRetry(),
                )
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
}
