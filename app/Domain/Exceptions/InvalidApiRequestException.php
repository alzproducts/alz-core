<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use Throwable;

/**
 * Thrown when an API request fails validation before being sent.
 *
 * Use cases:
 * - Malformed query syntax (e.g., invalid GAQL)
 * - Invalid parameter values or types
 * - SDK-level validation failures
 *
 * This is a programming error - our code constructed an invalid request.
 * Should NOT retry (permanent until code changes).
 *
 * @see InvalidApiResponseException For malformed responses from the API
 * @see ExternalServiceUnavailableException For transient failures (rate limits, outages)
 */
final class InvalidApiRequestException extends DomainException
{
    public function __construct(
        public readonly string $serviceName,
        string $message = 'API request validation failed',
        ?Throwable $previous = null,
    ) {
        parent::__construct("{$serviceName}: {$message}", 0, $previous);
    }
}
