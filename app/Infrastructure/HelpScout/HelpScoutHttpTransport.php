<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Infrastructure\Support\ApiRetryStrategy;
use Closure;
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
        private HelpScoutErrorHandler $errorHandler,
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
        return $this->executeWithAuthRetry(
            // @phpstan-ignore missingType.checkedException, missingType.checkedException (closure exceptions caught in executeWithAuthRetry)
            fn(): Response => $this->createRequest()
                ->send('GET', HelpScoutConfig::BASE_URL . $endpoint, ['query' => $queryParams])
                ->throw(),
        );
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

        $poolResults = $this->sendPool($requests);

        if ($this->poolHasAuthFailure($poolResults)) {
            $this->refreshAuthToken();
            $poolResults = $this->sendPool($requests);
        }

        return $this->processPoolResults($poolResults);
    }

    /**
     * Execute the concurrent GET pool with freshly-fetched auth headers.
     *
     * Auth headers are fetched ONCE per pool run to avoid per-request
     * token refresh race conditions.
     *
     * @param array<string, array<string, mixed>> $requests Keyed array of query params
     *
     * @return array<string, Response|Throwable>
     */
    private function sendPool(array $requests): array
    {
        /** @var array<string, string> $authHeaders */
        $authHeaders = $this->sdkClient->getAuthenticator()->getAuthHeader();

        /** @var array<string, Response|Throwable> */
        return $this->httpFactory->pool(fn(Pool $pool): array => $this->buildPoolGetRequests($pool, $requests, $authHeaders));
    }

    /**
     * Detect a 401 among pool results.
     *
     * In Http::pool an HTTP 401 arrives as a failed Response, not a thrown
     * exception — only connection-level failures arrive as Throwable.
     *
     * @param array<string, Response|Throwable> $poolResults
     */
    private function poolHasAuthFailure(array $poolResults): bool
    {
        return \array_any(
            $poolResults,
            static fn(Response|Throwable $result): bool => $result instanceof Response && $result->status() === 401,
        );
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
                throw $this->errorHandler->handleRequestException($e);
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
            throw $this->errorHandler->handleConnectionException($throwable);
        }

        if ($throwable instanceof RequestException) {
            throw $this->errorHandler->handleRequestException($throwable);
        }

        throw new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $throwable);
    }

    /**
     * Execute a request closure, re-minting the OAuth token and retrying once on a 401.
     *
     * The SDK hands out a cached bearer token without an expiry check, so an expired
     * token yields a 401. Refreshing re-mints it in the SDK's singleton authenticator;
     * the retried closure then sends the fresh token. A second 401 (creds genuinely
     * revoked) is translated to AuthenticationExpiredException — no infinite loop.
     * 403 is not retried: a refresh cannot grant missing permissions.
     *
     * @param-immediately-invoked-callable $request
     *
     * @param Closure(): Response $request
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401 after retry, or 403)
     * @throws ExternalServiceUnavailableException When API unavailable, rate limited, or connection fails
     */
    private function executeWithAuthRetry(Closure $request): Response
    {
        try {
            return $request();
        } catch (RequestException $e) {
            if ($e->response->status() === 401) {
                $this->refreshAuthToken();

                try {
                    return $request();
                } catch (RequestException $retryException) {
                    throw $this->errorHandler->handleRequestException($retryException);
                } catch (ConnectionException $retryException) {
                    throw $this->errorHandler->handleConnectionException($retryException);
                } catch (Exception $retryException) {
                    throw $this->errorHandler->handleUnexpectedException($retryException);
                }
            }

            throw $this->errorHandler->handleRequestException($e);
        } catch (ConnectionException $e) {
            throw $this->errorHandler->handleConnectionException($e);
        } catch (Exception $e) {
            throw $this->errorHandler->handleUnexpectedException($e);
        }
    }

    /**
     * Re-mint the OAuth access token via the SDK authenticator.
     *
     * The call mutates the SDK's in-memory singleton authenticator, so the next
     * getAuthHeader() returns the fresh token. A failed re-mint means the app
     * credentials themselves are dead (revoked/misconfigured) — a permanent outage
     * that must surface, not routine expiry.
     *
     * @throws AuthenticationExpiredException When the token re-mint fails
     */
    private function refreshAuthToken(): void
    {
        try {
            $this->sdkClient->getAuthenticator()->fetchAccessAndRefreshToken();
        } catch (Throwable $e) {
            Log::error(self::SERVICE_NAME . ' API token refresh failed', [
                'error' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);

            throw new AuthenticationExpiredException(self::SERVICE_NAME, 'Token refresh failed', $e);
        }
    }

    /**
     * Create configured HTTP request with auth and retry logic.
     *
     * Fetches the OAuth2 bearer token from the SDK's authenticator. Token refresh
     * on expiry is handled explicitly by executeWithAuthRetry (the SDK's own
     * refresh-on-401 flow is bypassed by this direct-HTTP transport).
     *
     * @throws RuntimeException When SDK fails to provide auth header (token refresh failure)
     */
    private function createRequest(): PendingRequest
    {
        /** @var array<string, string> $authHeaders */
        $authHeaders = $this->sdkClient->getAuthenticator()->getAuthHeader();

        return $this->httpFactory->withHeaders($authHeaders)
            ->retry(
                times: $this->config->retryAttempts,
                sleepMilliseconds: 100,
                when: ApiRetryStrategy::defaultRetry(),
            )
            ->timeout($this->config->timeoutSeconds);
    }
}
