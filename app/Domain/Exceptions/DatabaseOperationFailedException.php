<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

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
final class DatabaseOperationFailedException extends DomainException
{
    public function __construct(
        public readonly string $operation,
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct("Database {$operation} failed: {$reason}", 0, $previous);
    }
}
