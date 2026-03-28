<?php

declare(strict_types=1);

namespace App\Infrastructure\Exceptions;

use RuntimeException;

/**
 * Base exception for all infrastructure-layer external system failures.
 *
 * Extends RuntimeException because infrastructure exceptions represent runtime
 * failures when interacting with external systems (APIs, databases, file systems,
 * message queues) - not programming bugs (which would be LogicException).
 *
 * Infrastructure exceptions should be caught at layer boundaries and translated
 * to Domain exceptions before bubbling to Application layer. This keeps the
 * Application and Domain layers decoupled from external implementation details.
 */
abstract class InfrastructureException extends RuntimeException
{
    /**
     * Structured context for logging and error tracking (e.g. Sentry).
     *
     * Override in concrete exceptions to return dynamic data (IDs, names, etc.)
     * that should NOT be in the message string (to enable Sentry grouping).
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [];
    }
}
