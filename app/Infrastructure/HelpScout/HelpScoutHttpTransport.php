<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Infrastructure\Support\ApiRetryStrategy;
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
            throw HelpScoutErrorHandler::handleRequestException($e);
        } catch (ConnectionException $e) {
            throw HelpScoutErrorHandler::handleConnectionException($e);
        } catch (Exception $e) {
            throw HelpScoutErrorHandler::handleUnexpectedException($e);
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

        /** @var array<string, string> $authHeaders */
        $authHeaders = $this->sdkClient->getAuthenticator()->getAuthHeader();

        /**
         * @var array<string, Response|Throwable> $poolResults
         *
         * @phpstan-ignore staticMethod.dynamicCall
         */
        $poolResults = $this->httpFactory->pool(fn(Pool $pool): array => $this->buildPoolGetRequests($pool, $requests, $authHeaders));

        return $this->processPoolResults($poolResults);
    }

    /**
     * Build pool GET request definitions for concurrent execution.
     *
     * @param array<string, array<string, mixed>> $requests Keyed query params
     * @param array<string, string> $authHeaders Pre-fetched OAuth2 headers
     *
     * @return array<string, PromiseInterface|Response>
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
     * Process all pool results, translating failures to exceptions.
     *
     * @param array<string, Response|Throwable> $poolResults
     *
     * @return array<string, Response>
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     */
    private function processPoolResults(array $poolResults): array
    {
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
     * Handle a single pool GET result, translating failures to exceptions.
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     */
    private function handlePoolGetResult(string $key, Response|Throwable $result): Response
    {
        if ($result instanceof Throwable) {
            $this->handleThrowableResult($key, $result);
        }

        if ($result->failed()) {
            try {
                $result->throw();
            } catch (RequestException $e) {
                throw HelpScoutErrorHandler::handleRequestException($e);
            }
        }

        return $result;
    }

    /**
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     */
    private function handleThrowableResult(string $key, Throwable $throwable): never
    {
        Log::error(self::SERVICE_NAME . ' API pool request failed', [
            'key' => $key,
            'error' => $throwable->getMessage(),
            'exception_class' => $throwable::class,
        ]);

        if ($throwable instanceof ConnectionException) {
            throw HelpScoutErrorHandler::handleConnectionException($throwable);
        }

        if ($throwable instanceof RequestException) {
            throw HelpScoutErrorHandler::handleRequestException($throwable);
        }

        throw new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $throwable);
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

        /** @phpstan-ignore staticMethod.dynamicCall (Factory uses __call to proxy to PendingRequest) */
        return $this->httpFactory->withHeaders($authHeaders)
            ->retry(
                times: $this->config->retryAttempts,
                sleepMilliseconds: 100,
                when: ApiRetryStrategy::defaultRetry(),
            )
            ->timeout($this->config->timeoutSeconds);
    }
}
