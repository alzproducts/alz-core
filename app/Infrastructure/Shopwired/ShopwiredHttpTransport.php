<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired;

use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Infrastructure\Support\ApiRetryStrategy;
use App\Infrastructure\Support\RetryAfterParser;
use Closure;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
final readonly class ShopwiredHttpTransport
{
    private const string SERVICE_NAME = 'Shopwired';

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
                ->get($endpoint, $query)
                ->throw();
        } catch (RequestException $e) {
            throw $this->handleRequestException($e);
        } catch (ConnectionException $e) {
            throw $this->handleConnectionException($e);
        } catch (Exception $e) {
            // Catch-all for unexpected exceptions from Guzzle/Laravel internals
            throw $this->handleUnexpectedException($e);
        }
    }

    /**
     * Create configured HTTP request with auth and optional retry logic.
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
     * @return Closure(int, Exception): int Sleep duration in milliseconds
     */
    private function buildSleepClosure(RetryStrategy $strategy): Closure
    {
        $baseMs = $strategy->baseDelayMs();

        if (! $strategy->useExponentialBackoff()) {
            return static fn(int $attempt, Exception $e): int => $baseMs;
        }

        // Exponential backoff: 500ms → 1s → 2s → 4s → 8s (capped at 16s)
        return static fn(int $attempt, Exception $e): int => (int) \min($baseMs * (2 ** ($attempt - 1)), 16000);
    }

    /**
     * Handle HTTP request failures (4xx, 5xx responses).
     *
     * Rate limits (429) logged as WARNING (transient, recoverable).
     * Other errors logged as ERROR (unexpected failures).
     */
    private function handleRequestException(RequestException $e): ExternalServiceUnavailableException
    {
        $status = $e->response->status();

        if ($status === 429) {
            $retryAfter = RetryAfterParser::parse($e->response->header('Retry-After'));

            Log::warning(self::SERVICE_NAME . ' API rate limited', [
                'retry_after' => $retryAfter,
                'error' => $e->getMessage(),
            ]);

            return new ExternalServiceUnavailableException(self::SERVICE_NAME, $retryAfter, $e);
        }

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
