<?php

declare(strict_types=1);

namespace App\Infrastructure\ReviewsIo;

use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiRequestException;
use App\Infrastructure\Support\ApiRetryStrategy;
use App\Infrastructure\Support\RetryAfterParser;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP transport layer for Reviews.io API.
 *
 * Handles all HTTP concerns: authentication, retry logic, timeout configuration,
 * and exception translation. This separation allows the client to focus solely
 * on business logic (validation, response parsing).
 *
 * Key responsibilities:
 * - Configure HTTP client with auth credentials (query params, per Reviews.io API)
 * - Apply retry strategy for transient failures
 * - Translate HTTP exceptions to domain exceptions
 * - Log all failures with context before translation
 *
 * @template-pattern API Client HTTP Transport
 */
final readonly class ReviewsIoHttpTransport
{
    private const string SERVICE_NAME = 'Reviews.io';

    public function __construct(
        private ReviewsIoConfig $config,
    ) {}

    /**
     * Perform GET request to Reviews.io API.
     *
     * @param string $endpoint API endpoint (relative to base URL)
     * @param array<string, mixed> $queryParams Additional query parameters
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
                ->get($endpoint, $queryParams)
                ->throw();
        } catch (RequestException $e) {
            throw $this->handleRequestException($e);
        } catch (ConnectionException $e) {
            throw $this->handleConnectionException($e);
        } catch (Exception $e) {
            // Catch-all for unexpected exceptions (Guzzle internals, retry edge cases)
            // Laravel's retry() helper can rethrow any Exception from the HTTP callback
            throw $this->handleUnexpectedException($e);
        }
    }

    /**
     * Create configured HTTP request with auth and retry logic.
     */
    private function createRequest(): PendingRequest
    {
        return Http::baseUrl($this->config->baseUrl)
            ->withQueryParameters([
                'apikey' => $this->config->apiKey,
                'store' => $this->config->storeId,
            ])
            ->retry(
                times: $this->config->retryTimes,
                sleepMilliseconds: $this->config->retryDelay,
                when: ApiRetryStrategy::defaultRetry(),
            )
            ->timeout($this->config->timeout);
    }

    /**
     * Handle HTTP request failures (4xx, 5xx responses).
     *
     * Status code mapping:
     * - 400: InvalidApiRequestException (programming error, permanent)
     * - 401/403: AuthenticationExpiredException (credentials issue, permanent)
     * - 429: ExternalServiceUnavailableException (rate limit, transient)
     * - 5xx: ExternalServiceUnavailableException (server error, transient)
     *
     * Note: 404 Not Found is NOT handled specially - semantics are context-dependent.
     * Individual client methods should handle "resource not found" cases.
     */
    private function handleRequestException(RequestException $e): ExternalServiceUnavailableException|InvalidApiRequestException|AuthenticationExpiredException
    {
        $status = $e->response->status();

        // 400 Bad Request = our request is malformed (programming error)
        if ($status === 400) {
            Log::error(self::SERVICE_NAME . ' API invalid request', [
                'status' => $status,
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

        // 401/403 = authentication/authorization failure (credentials issue)
        if ($status === 401 || $status === 403) {
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

        // 429 = rate limited (transient, respect Retry-After)
        if ($status === 429) {
            $retryAfter = RetryAfterParser::parse($e->response->header('Retry-After'));

            Log::warning(self::SERVICE_NAME . ' API rate limited', [
                'retry_after' => $retryAfter,
                'error' => $e->getMessage(),
            ]);

            return new ExternalServiceUnavailableException(self::SERVICE_NAME, $retryAfter, $e);
        }

        // All other errors (5xx, 404, etc.) = service unavailable
        Log::error(self::SERVICE_NAME . ' API request failed', [
            'status' => $status,
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
     *
     * Laravel's retry() helper can rethrow any Exception from the HTTP callback.
     * This catch-all ensures nothing escapes the Infrastructure layer.
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
