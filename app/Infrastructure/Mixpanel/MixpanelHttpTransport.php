<?php

declare(strict_types=1);

namespace App\Infrastructure\Mixpanel;

use App\Domain\Exceptions\ExternalServiceUnavailableException;
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
 * HTTP transport layer for Mixpanel API.
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
final readonly class MixpanelHttpTransport
{
    private const string SERVICE_NAME = 'Mixpanel';

    public function __construct(
        private MixpanelConfig $config,
    ) {}

    /**
     * Perform HTTP request to Mixpanel API.
     *
     * @param string $method HTTP method (GET, POST, PUT, etc.)
     * @param string $url Full URL to request
     * @param string|null $body Request body content
     * @param string|null $contentType Content-Type header value
     * @param bool $retry Whether to apply retry logic for transient failures
     *
     * @return Response Successful HTTP response
     *
     * @throws ExternalServiceUnavailableException When API unavailable, rate limited, or connection fails
     */
    public function request(
        string $method,
        string $url,
        ?string $body = null,
        ?string $contentType = null,
        bool $retry = true,
    ): Response {
        try {
            $request = $this->createBaseRequest($retry);

            if (($body !== null) && ($contentType !== null)) {
                $request = $request->withBody($body, $contentType);
            }

            return $request
                ->send($method, $url)
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
    private function createBaseRequest(bool $retry): PendingRequest
    {
        $request = Http::withBasicAuth(
            $this->config->serviceAccountUsername,
            $this->config->serviceAccountPassword,
        )->timeout($this->config->timeout);

        if ($retry) {
            $request = $request->retry(
                times: $this->config->retryTimes,
                sleepMilliseconds: $this->config->retryDelay,
                when: ApiRetryStrategy::defaultRetry(),
            );
        }

        return $request;
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
