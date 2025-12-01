<?php

declare(strict_types=1);

namespace App\Application\Exceptions;

use RuntimeException;

/**
 * Base exception for all application-layer use case failures.
 *
 * Extends RuntimeException because application exceptions represent runtime
 * orchestration failures (batch processing errors, job failures, coordination
 * issues) - not programming bugs (which would be LogicException).
 *
 * Application exceptions indicate failures in use case execution or business
 * process coordination, distinct from domain rule violations (DomainException)
 * or infrastructure failures (InfrastructureException).
 */
abstract class ApplicationException extends RuntimeException {}
