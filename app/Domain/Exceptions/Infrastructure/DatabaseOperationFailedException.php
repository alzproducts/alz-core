<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Infrastructure;

use Override;
use Throwable;

/**
 * Thrown when a database operation fails permanently.
 *
 * Use cases:
 * - Foreign key constraint violations
 * - Invalid column references
 * - Schema errors
 *
 * This is a permanent error - jobs should NOT retry.
 */
final class DatabaseOperationFailedException extends AbstractInfrastructureException
{
    public function __construct(
        public readonly string $operation,
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Database operation failed', 0, $previous);
    }

    #[Override]
    public function context(): array
    {
        return ['operation' => $this->operation, 'reason' => $this->reason];
    }
}
