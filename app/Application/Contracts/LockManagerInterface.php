<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use Closure;

/**
 * Distributed lock manager for exclusive resource access.
 *
 * Unlike LockableCacheInterface which gracefully degrades on lock failure,
 * this interface enforces strict locking - the operation MUST NOT proceed
 * without the lock. Use for operations where concurrent execution causes
 * data corruption (e.g., SKU generation race conditions).
 */
interface LockManagerInterface
{
    /**
     * Execute callback under exclusive lock protection.
     *
     * @template T
     *
     * @param string $name Lock identifier (e.g., 'sku-generation')
     * @param int $timeoutSeconds Maximum seconds to wait for lock acquisition
     * @param-immediately-invoked-callable $callback
     * @param Closure(): T $callback Operation to execute under lock
     *
     * @return T Result from callback
     *
     * @throws LockAcquisitionException When lock cannot be acquired within timeout
     */
    public function withLock(string $name, int $timeoutSeconds, Closure $callback): mixed;
}
