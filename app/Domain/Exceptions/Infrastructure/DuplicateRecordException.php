<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Infrastructure;

use Override;
use Throwable;

/**
 * Thrown when a unique constraint violation occurs.
 *
 * This is a permanent error - the operation cannot succeed
 * without changing the data. Jobs should NOT retry.
 */
final class DuplicateRecordException extends AbstractInfrastructureException
{
    public function __construct(
        public readonly string $table,
        public readonly string $constraint,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Duplicate record constraint violation', 0, $previous);
    }

    #[Override]
    public function context(): array
    {
        return ['table' => $this->table, 'constraint' => $this->constraint];
    }
}
