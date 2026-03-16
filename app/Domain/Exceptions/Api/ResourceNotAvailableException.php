<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Api;

use Throwable;

/**
 * Resource not yet available in external service.
 *
 * Thrown when a requested resource may exist but is not yet queryable,
 * typically due to eventual consistency lag (e.g., webhook arrives before
 * the GET API has the resource available).
 *
 * This is a transient error — retrying after a short delay usually succeeds.
 *
 * @see ResourceNotFoundException For genuinely missing resources (permanent)
 */
final class ResourceNotAvailableException extends TransientApiFailure
{
    public function __construct(
        string $serviceName,
        public readonly string $resourceType,
        public readonly int|string $resourceId,
        ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $serviceName,
            $retryAfter,
            "{$resourceType} with ID '{$resourceId}' not available in {$serviceName} — may not exist or may not be available yet",
            $previous,
        );
    }
}
