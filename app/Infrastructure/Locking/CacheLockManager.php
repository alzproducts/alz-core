<?php

declare(strict_types=1);

namespace App\Infrastructure\Locking;

use App\Application\Contracts\LockManagerInterface;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use Closure;
use Exception;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Lock;
use Psr\Log\LoggerInterface;

/**
 * Redis-backed distributed lock manager.
 *
 * Uses Laravel's cache lock mechanism (atomic Redis SETNX) to provide
 * exclusive access for critical operations like SKU generation.
 *
 * Unlike LockableCache which gracefully degrades, this implementation
 * enforces strict locking - operations fail if locks cannot be acquired,
 * including when the lock store itself is unreachable.
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
        private LoggerInterface $logger,
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
        $lock = $this->acquire($name, $timeoutSeconds);

        try {
            return $callback();
        } finally {
            $this->release($lock, $name);
        }
    }

    /**
     * @throws LockAcquisitionException on a wait timeout or a lock-store failure
     */
    private function acquire(string $name, int $timeoutSeconds): Lock
    {
        try {
            $lock = $this->cache->lock("lock:{$name}", $timeoutSeconds + self::HOLD_BUFFER_SECONDS);
            $lock->block($timeoutSeconds);

            return $lock;
        } catch (Exception $e) {
            throw new LockAcquisitionException($name, $timeoutSeconds, $e);
        }
    }

    private function release(Lock $lock, string $name): void
    {
        try {
            $lock->release();
        } catch (Exception $e) { // @ignoreException - the lock expires after its hold time; throwing would mask the callback's outcome
            $this->logger->warning('Lock release failed', [
                'lock' => $name,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
