<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Api;

use Override;
use Throwable;

/**
 * Resource not found in external service.
 *
 * Thrown when a requested resource (order, customer, product, etc.) does not exist.
 * This is a permanent error - retrying won't help (unlike ExternalServiceUnavailableException).
 *
 * Use cases:
 * - API returns 404 for a specific resource ID
 * - Search returns no results for a known identifier
 */
final class ResourceNotFoundException extends PermanentApiFailure
{
    public function __construct(
        string $serviceName,
        public readonly string $resourceType,
        public readonly int|string $resourceId,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $serviceName,
            'Resource not found in external service',
            $previous,
        );
    }

    #[Override]
    public function context(): array
    {
        return [
            ...parent::context(),
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
        ];
    }
}
