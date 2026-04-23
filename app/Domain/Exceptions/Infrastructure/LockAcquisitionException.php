<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Infrastructure;

use Override;
use Throwable;

/**
 * Failed to acquire a distributed lock within the timeout period.
 *
 * Thrown when a process cannot acquire exclusive access to a shared resource
 * (e.g., SKU generation). This is a transient failure that can be retried.
 */
final class LockAcquisitionException extends AbstractInfrastructureException
{
    public function __construct(
        public readonly string $lockName,
        public readonly int $timeoutSeconds,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Failed to acquire lock', previous: $previous);
    }

    #[Override]
    public function context(): array
    {
        return [
            'lock_name' => $this->lockName,
            'timeout_seconds' => $this->timeoutSeconds,
        ];
    }
}
