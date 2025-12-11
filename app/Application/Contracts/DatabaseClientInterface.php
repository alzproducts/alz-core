<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Domain\Exceptions\DatabaseOperationFailedException;
use App\Domain\Exceptions\DuplicateRecordException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use Closure;

/**
 * Database client interface for executing operations with exception translation.
 *
 * Implementations translate database-specific exceptions to domain exceptions,
 * enabling callers to determine if errors are transient (retry) or permanent (fail).
 *
 * Usage: Callers inject their database connection directly (in Infrastructure layer)
 * and capture it in the closure. This keeps the interface framework-agnostic while
 * providing full typing support in implementations.
 */
interface DatabaseClientInterface
{
    /**
     * Execute a database operation with exception translation.
     *
     * @template T
     *
     * @param Closure(): T $operation Closure performing database operation
     *
     *
     * @phpstan-return T
     *
     * @throws ExternalServiceUnavailableException When database temporarily unavailable (transient - retry)
     * @throws DuplicateRecordException When unique constraint violated (permanent - no retry)
     * @throws DatabaseOperationFailedException When query fails permanently (permanent - no retry)
     */
    public function execute(Closure $operation): mixed;
}
