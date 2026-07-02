<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Infrastructure\Support\RetryAfterParser;
use App\Infrastructure\Support\TransientLogThrottle;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

final readonly class LinnworksErrorHandler
{
    private const string SERVICE_NAME = 'Linnworks';

    private const string SERVICE_KEY = 'linnworks';

    /**
     * Substrings identifying a transient backend failure that Linnworks wraps in an HTTP 400.
     *
     * The Dashboards/ExecuteCustomScriptQuery endpoint runs SQL against Linnworks' own
     * database; when that backend is overloaded it returns a 400 whose body is a SQL Server
     * connection-timeout message rather than a real validation error. Matched case-insensitively.
     */
    private const array TRANSIENT_BAD_REQUEST_SIGNATURES = [
        'Connection Timeout Expired',
        'pre-login handshake',
        'timeout period elapsed',
    ];

    public function __construct(
        private TransientLogThrottle $logThrottle,
    ) {}

    /**
     * Route HTTP failures to specific handlers by status code.
     */
    public function handleRequestException(
        RequestException $e,
        string $endpoint,
    ): InvalidApiRequestException|AuthenticationExpiredException|ResourceNotFoundException|ExternalServiceUnavailableException {
        return match ($e->response->status()) {
            400, 414, 422 => $this->handleBadRequest($e),
            401, 403 => self::handleAuthenticationFailure($e),
            404 => self::handleNotFound($e, $endpoint),
            429 => self::handleRateLimit($e),
            default => $this->handleServerError($e),
        };
    }

    /**
     * Handle 400/414/422 Bad Request.
     *
     * Normally a malformed request (permanent — no retry). EXCEPTION: Linnworks reports some
     * transient backend failures (SQL connection timeouts) with a 400 status; those are detected
     * by response-body signature and translated to a transient outage so the job retries instead
     * of failing permanently.
     */
    private function handleBadRequest(RequestException $e): InvalidApiRequestException|ExternalServiceUnavailableException
    {
        $rawMessage = $e->response->json('Message');
        $message = \is_string($rawMessage) ? $rawMessage : null;

        if ($message !== null && self::isTransientFailureMessage($message)) {
            return $this->handleTransientBadRequest($e, $message);
        }

        return self::handlePermanentBadRequest($e, $message);
    }

    private function handleTransientBadRequest(RequestException $e, string $message): ExternalServiceUnavailableException
    {
        Log::warning(self::SERVICE_NAME . ' API transient failure reported as 400', [
            'status' => $e->response->status(),
            'response_message' => $message,
        ]);

        return new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
    }

    private static function handlePermanentBadRequest(RequestException $e, ?string $message): InvalidApiRequestException
    {
        Log::error(self::SERVICE_NAME . ' API invalid request', [
            'status' => $e->response->status(),
            'error' => $e->getMessage(),
            'response_message' => $message ?? 'No message provided',
        ]);

        return new InvalidApiRequestException(
            self::SERVICE_NAME,
            $message ?? 'Invalid request parameters',
            $e,
        );
    }

    /**
     * Detect a Linnworks transient-failure message delivered with a 400 status.
     */
    private static function isTransientFailureMessage(string $message): bool
    {
        return \array_any(
            self::TRANSIENT_BAD_REQUEST_SIGNATURES,
            static fn(string $signature): bool => \mb_stripos($message, $signature) !== false,
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
     * Handle 404 Not Found (resource doesn't exist - permanent).
     */
    private static function handleNotFound(RequestException $e, string $endpoint): ResourceNotFoundException
    {
        Log::warning(self::SERVICE_NAME . ' API resource not found', [
            'endpoint' => $endpoint,
            'error' => $e->getMessage(),
        ]);

        return new ResourceNotFoundException(self::SERVICE_NAME, $endpoint, 'unknown');
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
        $this->logThrottle->logTransient(self::SERVICE_KEY, self::SERVICE_NAME . ' API request failed', [
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
        $this->logThrottle->logTransient(self::SERVICE_KEY, self::SERVICE_NAME . ' API connection failed', [
            'error' => $e->getMessage(),
        ]);

        return new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
    }

    /**
     * Handle unexpected exceptions from Guzzle/Laravel internals.
     */
    public function handleUnexpectedException(Exception $e): ExternalServiceUnavailableException
    {
        $this->logThrottle->logTransient(self::SERVICE_KEY, self::SERVICE_NAME . ' API unexpected error', [
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);

        return new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
    }

}
