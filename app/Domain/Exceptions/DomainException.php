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
abstract class DomainException extends RuntimeException {}
