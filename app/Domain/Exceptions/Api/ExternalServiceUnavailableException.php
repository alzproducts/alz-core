<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Api;

use Throwable;

/**
 * Thrown when an external service is temporarily unavailable.
 *
 * Use cases:
 * - Rate limiting (429) → pass retryAfter from API
 * - Service outage (503) → retryAfter null, let Laravel backoff
 * - Network timeout → retryAfter null, let Laravel backoff
 */
final class ExternalServiceUnavailableException extends AbstractApiException
{
    public function __construct(
        public readonly string $serviceName,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct("External service '{$serviceName}' is unavailable", 0, $previous);
    }
}
