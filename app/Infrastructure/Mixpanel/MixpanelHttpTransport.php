<?php

declare(strict_types=1);

namespace App\Infrastructure\Mixpanel;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Infrastructure\Mixpanel\Contracts\MixpanelTransportInterface;
use App\Infrastructure\Support\ApiRetryStrategy;
use App\Infrastructure\Support\RetryAfterParser;
use App\Infrastructure\Support\TransientLogThrottle;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

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
final readonly class MixpanelHttpTransport implements MixpanelTransportInterface
{
    private const string SERVICE_NAME = 'Mixpanel';

    private const string SERVICE_KEY = 'mixpanel';

    public function __construct(
        private MixpanelConfig $config,
        private TransientLogThrottle $logThrottle,
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
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
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
            throw $this->handleRequestException($e, $url);
        } catch (ConnectionException $e) {
            throw $this->handleConnectionException($e);
        } catch (Exception $e) {
            // Catch-all for unexpected exceptions from Guzzle/Laravel internals
            throw $this->handleUnexpectedException($e);
        }
    }

    /**
     * Create configured HTTP request with auth and optional retry logic.
     *
     * @throws RuntimeException When HTTP client configuration fails
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
     * Route HTTP failures to specific handlers by status code.
     *
     * @param string $url The URL that was called (for 404 context)
     */
    private function handleRequestException(
        RequestException $e,
        string $url,
    ): InvalidApiRequestException|AuthenticationExpiredException|ExternalServiceUnavailableException {
        return match ($e->response->status()) {
            400, 422 => $this->handleBadRequest($e),
            401, 403 => $this->handleAuthenticationFailure($e),
            404 => $this->handleNotFound($e, $url),
            429 => $this->handleRateLimit($e),
            default => $this->handleServerError($e),
        };
    }

    /**
     * Handle 400/422 Bad Request (malformed request - programming error).
     */
    private function handleBadRequest(RequestException $e): InvalidApiRequestException
    {
        $body = $e->response->json();

        Log::error(self::SERVICE_NAME . ' API invalid request', [
            'status' => $e->response->status(),
            'error' => $e->getMessage(),
            'response' => $body,
        ]);

        $message = \is_array($body) ? ($body['message'] ?? null) : null;

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
     * Handle 404 Not Found (invalid endpoint - programming error).
     *
     * Mixpanel endpoints are fixed URLs, so 404 indicates a wrong endpoint was called.
     */
    private function handleNotFound(RequestException $e, string $url): InvalidApiRequestException
    {
        Log::error(self::SERVICE_NAME . ' API endpoint not found', [
            'url' => $url,
            'status' => 404,
            'error' => $e->getMessage(),
        ]);

        return new InvalidApiRequestException(
            self::SERVICE_NAME,
            "Endpoint not found: {$url}",
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
        $this->logTransient(self::SERVICE_NAME . ' API request failed', [
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
        $this->logTransient(self::SERVICE_NAME . ' API connection failed', [
            'error' => $e->getMessage(),
        ]);

        return new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
    }

    /**
     * Handle unexpected exceptions from Guzzle/Laravel internals.
     */
    private function handleUnexpectedException(Exception $e): ExternalServiceUnavailableException
    {
        $this->logTransient(self::SERVICE_NAME . ' API unexpected error', [
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);

        return new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logTransient(string $message, array $context): void
    {
        $window = $this->logThrottle->check(self::SERVICE_KEY);

        if ($window !== null) {
            Log::error($message, [...$context, 'note' => "Subsequent transient failures suppressed for {$window} minutes"]);
        } else {
            Log::warning($message, $context);
        }
    }
}
