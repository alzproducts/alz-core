<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use RuntimeException;

/**
 * Base exception for all domain-layer business rule violations.
 *
 * Extends RuntimeException because domain exceptions represent runtime
 * conditions (external failures, business constraints) - not programming
 * bugs (which would be LogicException).
 *
 * Note: This is NOT PHP's built-in \DomainException (which extends LogicException
 * for mathematical domain errors). This is our application's domain layer base.
 */
abstract class DomainException extends RuntimeException
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
