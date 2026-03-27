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
final class ExternalServiceUnavailableException extends TransientApiFailure
{
    public function __construct(
        string $serviceName,
        ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $serviceName,
            $retryAfter,
            'External service unavailable',
            $previous,
        );
    }
}
