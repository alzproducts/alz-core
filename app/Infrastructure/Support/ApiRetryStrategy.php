<?php

declare(strict_types=1);

namespace App\Infrastructure\Support;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Retry strategies for external API calls.
 *
 * Provides reusable retry logic for HTTP clients calling third-party APIs.
 * Handles transient failures (network errors, server errors, rate limiting)
 * while avoiding retry on permanent client errors.
 */
final class ApiRetryStrategy
{
    /**
     * Default retry strategy for external API calls.
     *
     * Retries on:
     * - Connection errors (network timeouts, DNS failures, connection refused)
     * - Server errors (5xx status codes)
     * - Rate limiting (429 Too Many Requests)
     *
     * Does NOT retry on:
     * - Client errors (4xx) except 429
     * - Invalid requests (401, 403, 404, etc.)
     * - Authentication failures (401)
     */
    public static function defaultRetry(): Closure
    {
        return static function (Throwable $exception): bool {
            // Retry on connection errors (network failures, timeouts, DNS issues)
            if ($exception instanceof ConnectionException) {
                return true;
            }

            // Retry on server errors (5xx) and rate limiting (429)
            if ($exception instanceof RequestException) {
                return $exception->response->serverError()
                       || ($exception->response->status() === 429);
            }

            return false;
        };
    }
}
