<?php

declare(strict_types=1);

namespace App\Infrastructure\ReviewsIo;

use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Infrastructure\Support\ApiRetryStrategy;
use App\Infrastructure\Support\RetryAfterParser;
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
}
