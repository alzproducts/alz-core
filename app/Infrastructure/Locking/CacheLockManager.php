<?php

declare(strict_types=1);

namespace App\Infrastructure\Locking;

use App\Application\Contracts\LockManagerInterface;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use Closure;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\LockTimeoutException;

/**
 * Redis-backed distributed lock manager.
 *
 * Uses Laravel's cache lock mechanism (atomic Redis SETNX) to provide
 * exclusive access for critical operations like SKU generation.
 *
 * Unlike LockableCache which gracefully degrades, this implementation
 * enforces strict locking - operations fail if locks cannot be acquired.
 */
final readonly class CacheLockManager implements LockManagerInterface
{
    /**
     * Extra time added to hold duration beyond wait timeout.
     *
     * Ensures lock doesn't expire while callback is executing.
     */
    private const int HOLD_BUFFER_SECONDS = 10;

    public function __construct(
        private CacheManager $cache,
    ) {}

    /**
     * @template T
     * @param-immediately-invoked-callable $callback
     * @param Closure(): T $callback
     *
     * @return T
     *
     * @throws LockAcquisitionException
     */
    public function withLock(string $name, int $timeoutSeconds, Closure $callback): mixed
    {
        $holdTime = $timeoutSeconds + self::HOLD_BUFFER_SECONDS;
        $lock = $this->cache->lock("lock:{$name}", $holdTime);

        try {
            $lock->block($timeoutSeconds);
        } catch (LockTimeoutException $e) {
            throw new LockAcquisitionException($name, $timeoutSeconds, $e);
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
