<?php

declare(strict_types=1);

namespace App\Infrastructure\ClickUp;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Infrastructure\Support\RetryAfterParser;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * Error handler for ClickUp HTTP transport.
 *
 * Translates HTTP exceptions to domain exceptions with logging.
 * Stateless — all methods are static.
 */
final class ClickUpErrorHandler
{
    private const string SERVICE_NAME = 'ClickUp';

    public static function handleRequestException(
        RequestException $e,
        string $endpoint,
    ): InvalidApiRequestException|AuthenticationExpiredException|ResourceNotFoundException|ExternalServiceUnavailableException {
        return match ($e->response->status()) {
            400, 422 => self::handleBadRequest($e, $endpoint),
            401, 403 => self::handleAuthenticationFailure($e, $endpoint),
            404 => self::handleNotFound($e, $endpoint),
            429 => self::handleRateLimit($e, $endpoint),
            default => self::handleServerError($e, $endpoint),
        };
    }

    private static function handleBadRequest(RequestException $e, string $endpoint): InvalidApiRequestException
    {
        $body = $e->response->json();

        Log::error(self::SERVICE_NAME . ' API invalid request', [
            'status' => $e->response->status(),
            'endpoint' => $endpoint,
            'error' => $e->getMessage(),
            'response' => $body,
        ]);

        $message = \is_array($body) ? ($body['err'] ?? null) : null;

        return new InvalidApiRequestException(
            self::SERVICE_NAME,
            \is_string($message) ? $message : 'Invalid request parameters',
            $e,
        );
    }

    private static function handleAuthenticationFailure(RequestException $e, string $endpoint): AuthenticationExpiredException
    {
        $status = $e->response->status();

        Log::error(self::SERVICE_NAME . ' API authentication failed', [
            'status' => $status,
            'endpoint' => $endpoint,
            'error' => $e->getMessage(),
        ]);

        return new AuthenticationExpiredException(
            self::SERVICE_NAME,
            ($status === 401) ? 'Invalid API key' : 'Insufficient permissions',
            $e,
        );
    }

    /**
     * 404 from a generic transport: the handler cannot know which resource type was being fetched
     * (clients call with different endpoints — `/user`, `/list/{id}/task`, `/task/{id}`). Resource
     * type is reported as 'unknown' and the endpoint URL is included in context for triage.
     */
    private static function handleNotFound(RequestException $e, string $endpoint): ResourceNotFoundException
    {
        Log::warning(self::SERVICE_NAME . ' API resource not found', [
            'endpoint' => $endpoint,
            'error' => $e->getMessage(),
        ]);

        return new ResourceNotFoundException(self::SERVICE_NAME, 'unknown', $endpoint, $e);
    }

    private static function handleRateLimit(RequestException $e, string $endpoint): ExternalServiceUnavailableException
    {
        $retryAfter = RetryAfterParser::parse($e->response->header('Retry-After'));

        Log::warning(self::SERVICE_NAME . ' API rate limited', [
            'endpoint' => $endpoint,
            'retry_after' => $retryAfter,
            'error' => $e->getMessage(),
        ]);

        return new ExternalServiceUnavailableException(self::SERVICE_NAME, $retryAfter, $e);
    }

    private static function handleServerError(RequestException $e, string $endpoint): ExternalServiceUnavailableException
    {
        Log::error(self::SERVICE_NAME . ' API request failed', [
            'status' => $e->response->status(),
            'endpoint' => $endpoint,
            'error' => $e->getMessage(),
        ]);

        return new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
    }

    public static function handleConnectionException(ConnectionException $e, string $endpoint): ExternalServiceUnavailableException
    {
        Log::error(self::SERVICE_NAME . ' API connection failed', [
            'endpoint' => $endpoint,
            'error' => $e->getMessage(),
        ]);

        return new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
    }

    public static function handleUnexpectedException(Exception $e, string $endpoint): ExternalServiceUnavailableException
    {
        Log::error(self::SERVICE_NAME . ' API unexpected error', [
            'endpoint' => $endpoint,
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);

        return new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
    }

    public static function handleUnparseableResponse(Exception $e): InvalidApiResponseException
    {
        Log::error(self::SERVICE_NAME . ' API unparseable response', [
            'error' => $e->getMessage(),
        ]);

        return new InvalidApiResponseException(self::SERVICE_NAME, 'Unparseable response body', $e);
    }
}
