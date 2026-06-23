<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Infrastructure\Support\RetryAfterParser;
use App\Infrastructure\Support\TransientLogThrottle;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

final readonly class ShopwiredErrorHandler
{
    private const string SERVICE_NAME = 'Shopwired';

    private const string SERVICE_KEY = 'shopwired';

    public function __construct(
        private TransientLogThrottle $logThrottle,
    ) {}

    /**
     * Route HTTP failures to specific handlers by status code.
     *
     * @param string $endpoint The endpoint that was called (for 404 context)
     */
    public function handleRequestException(
        RequestException $e,
        string $endpoint,
    ): InvalidApiRequestException|AuthenticationExpiredException|ResourceNotAvailableException|ExternalServiceUnavailableException {
        return match ($e->response->status()) {
            400, 422 => self::handleBadRequest($e, $endpoint),
            401, 403 => self::handleAuthenticationFailure($e),
            404 => self::handleNotFound($e, $endpoint),
            429 => self::handleRateLimit($e),
            default => $this->handleServerError($e),
        };
    }

    /**
     * Handle 400/422 Bad Request (malformed request - programming error).
     */
    private static function handleBadRequest(RequestException $e, string $endpoint): InvalidApiRequestException
    {
        $body = $e->response->json();

        Log::error(self::SERVICE_NAME . ' API invalid request', [
            'status' => $e->response->status(),
            'endpoint' => $endpoint,
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
    private static function handleAuthenticationFailure(RequestException $e): AuthenticationExpiredException
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
     * Handle 404 Not Found — treated as transient (possible consistency lag).
     */
    private static function handleNotFound(RequestException $e, string $endpoint): ResourceNotAvailableException
    {
        Log::warning(self::SERVICE_NAME . ' API returned 404, treating as transient (possible consistency lag)', [
            'endpoint' => $endpoint,
            'error' => $e->getMessage(),
        ]);

        return new ResourceNotAvailableException(self::SERVICE_NAME, $endpoint, 'unknown', retryAfter: 30, previous: $e);
    }

    /**
     * Handle 429 Rate Limit (transient - respect Retry-After).
     */
    private static function handleRateLimit(RequestException $e): ExternalServiceUnavailableException
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
    public function handleServerError(RequestException $e): ExternalServiceUnavailableException
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
    public function handleConnectionException(ConnectionException $e): ExternalServiceUnavailableException
    {
        $this->logTransient(self::SERVICE_NAME . ' API connection failed', [
            'error' => $e->getMessage(),
        ]);

        return new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
    }

    /**
     * Handle unexpected exceptions from Guzzle/Laravel internals.
     */
    public function handleUnexpectedException(Exception $e): ExternalServiceUnavailableException
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
