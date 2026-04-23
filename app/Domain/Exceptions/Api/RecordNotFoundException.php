<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Api;

use Throwable;

/**
 * Record not found in local database.
 *
 * Thrown when a DB read or write targets a row that no longer exists.
 * This is a transient error - concurrent sync/delete transactions can
 * race against each other, so retrying with backoff typically resolves it.
 *
 * @see ResourceNotFoundException For permanent external-service 404s
 */
final class RecordNotFoundException extends TransientApiFailure
{
    public function __construct(
        public readonly string $resourceType,
        public readonly int|string $resourceId,
        ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            serviceName: 'Database',
            retryAfter: $retryAfter,
            message: 'Record not found in database',
            previous: $previous,
        );
    }

    public function context(): array
    {
        return [
            ...parent::context(),
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
        ];
    }
}
