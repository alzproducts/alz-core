<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout;

use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiRequestException;
use App\Infrastructure\Support\ApiRetryStrategy;
use App\Infrastructure\Support\RetryAfterParser;
use Exception;
use HelpScout\Api\ApiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
     * Create configured HTTP request with auth and retry logic.
     *
     * Uses the SDK's authenticator to get the OAuth2 bearer token,
     * which handles token refresh automatically.
     */
    private function createRequest(): PendingRequest
    {
        $authHeaders = $this->sdkClient->getAuthenticator()->getAuthHeader();

        return Http::withHeaders($authHeaders)
            ->retry(
                times: $this->config->retryAttempts,
                sleepMilliseconds: 100,
                when: ApiRetryStrategy::defaultRetry(),
            )
            ->timeout($this->config->timeoutSeconds);
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
