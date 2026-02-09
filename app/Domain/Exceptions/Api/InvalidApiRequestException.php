<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Api;

use Throwable;

/**
 * Thrown when the API rejects our request (e.g., 400 Bad Request).
 *
 * Use cases:
 * - Malformed query syntax (e.g., invalid GAQL)
 * - Invalid parameter values or types
 * - SDK-level validation failures
 * - Request data serialization failures (e.g., json_encode)
 * - User-provided data rejected by API validation
 *
 * May indicate a programming error OR invalid user input that couldn't
 * be validated locally (APIs don't document all validation rules).
 * Should NOT retry (permanent until request is corrected).
 *
 * @see InvalidApiResponseException For malformed responses from the API
 * @see ExternalServiceUnavailableException For transient failures (rate limits, outages)
 */
final class InvalidApiRequestException extends PermanentApiFailure
{
    public function __construct(
        string $serviceName,
        string $message = 'API request validation failed',
        ?Throwable $previous = null,
    ) {
        parent::__construct($serviceName, "{$serviceName}: {$message}", $previous);
    }
}
