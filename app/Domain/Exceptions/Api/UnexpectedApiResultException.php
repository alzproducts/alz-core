<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Api;

use Throwable;

/**
 * Thrown when an external API returns an unexpected but valid response.
 *
 * Use cases:
 * - API returns empty data when data is expected (e.g., zero campaigns)
 * - API response structure is valid but content is unexpected
 * - Configuration issues (wrong account ID, missing permissions)
 *
 * This is NOT a transient failure - retrying won't help.
 * Requires human investigation to resolve.
 */
final class UnexpectedApiResultException extends PermanentApiFailure
{
    public function __construct(
        string $serviceName,
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $serviceName,
            'Unexpected result from external service',
            $previous,
        );
    }

    public function context(): array
    {
        return [...parent::context(), 'reason' => $this->reason];
    }
}
