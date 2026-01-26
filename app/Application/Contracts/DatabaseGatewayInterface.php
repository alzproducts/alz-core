<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Closure;

/**
 * Database gateway interface for executing operations with exception translation.
 *
 * Implementations translate database-specific exceptions to domain exceptions,
 * enabling callers to determine if errors are transient (retry) or permanent (fail).
 *
 * This interface is framework-agnostic. Callers capture their database connection
 * in the closure (injected separately in Infrastructure layer).
 */
interface DatabaseGatewayInterface
{
    /**
     * Execute a read operation with exception translation.
     *
     * Use for SELECT queries, existence checks, counts.
     *
     * @template T
     *
     * @param-immediately-invoked-callable $operation
     *
     * @param Closure(): T $operation Closure performing read operation
     *
     * @phpstan-return T
     *
     * @throws ExternalServiceUnavailableException When database temporarily unavailable (transient - retry)
     * @throws DuplicateRecordException When unique constraint violated (defensive - shouldn't occur in reads)
     * @throws DatabaseOperationFailedException When query fails permanently (permanent - no retry)
     */
    public function query(Closure $operation): mixed;

    /**
     * Execute a write operation within a transaction with exception translation.
     *
     * Use for INSERT, UPDATE, DELETE, or multi-step operations requiring atomicity.
     *
     * Combines transaction management with exception translation:
     * - Opens transaction before executing
     * - Commits on success
     * - Rolls back on any exception (retries on deadlock up to $attempts total attempts)
     * - Translates database exceptions to domain exceptions
     *
     * @template T
     *
     * @param-immediately-invoked-callable $operation
     *
     * @param Closure(): T $operation Closure performing write operation
     * @param int $attempts Total attempts on deadlock (1 = no retry, 3 = up to 2 retries)
     *
     * @phpstan-return T
     *
     * @throws ExternalServiceUnavailableException When database temporarily unavailable (transient - retry)
     * @throws DuplicateRecordException When unique constraint violated (permanent - no retry)
     * @throws DatabaseOperationFailedException When query fails permanently (permanent - no retry)
     */
    public function transact(Closure $operation, int $attempts = 1): mixed;
}
