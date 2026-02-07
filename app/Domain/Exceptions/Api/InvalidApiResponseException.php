<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Api;

use Throwable;

/**
 * Thrown when an external API returns a malformed response.
 *
 * Use cases:
 * - Spatie DTO validation failed (response structure changed)
 * - Required fields missing or null
 * - Type mismatches in response data
 *
 * This is a programming error - the API contract changed and code needs updating.
 * Should NOT retry (permanent until code changes).
 *
 * @see UnexpectedApiResultException For valid but unexpected content (empty data, wrong config)
 * @see ExternalServiceUnavailableException For transient failures (rate limits, outages)
 */
final class InvalidApiResponseException extends PermanentApiFailure
{
    public function __construct(
        string $serviceName,
        string $message = 'API response validation failed',
        ?Throwable $previous = null,
    ) {
        parent::__construct($serviceName, "{$serviceName}: {$message}", $previous);
    }
}
