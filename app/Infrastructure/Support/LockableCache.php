<?php

declare(strict_types=1);

namespace App\Infrastructure\Support;

use App\Application\Contracts\LockableCacheInterface;
use Closure;
use Exception;
use Illuminate\Cache\CacheManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Cache implementation with atomic locking for concurrent-safe refresh operations.
 *
 * Wraps Laravel's CacheManager to provide:
 * - Graceful degradation on cache/lock failures
 * - Thundering herd protection via atomic locks
 * - Double-check pattern after lock acquisition
 * - Stale value fallback option
 *
 * All infrastructure exceptions (Redis, lock timeouts) are caught and logged.
 * Only factory callback exceptions propagate to callers.
 */
final readonly class LockableCache implements LockableCacheInterface
{
    private const int LOCK_TIMEOUT_SECONDS = 30;
    private const int LOCK_WAIT_SECONDS = 10;

    public function __construct(
        private CacheManager $cache,
        private LoggerInterface $logger,
        private string $serviceName = 'cache',
    ) {}

    public function forget(string $key): void
    {
        try {
            $this->cache->forget($key);
        } catch (Throwable $e) {
            $this->logInfrastructureFailure('delete', $key, $e);
        }
    }

    /**
     * @template TValue
     * @param-immediately-invoked-callable $factory
     * @param Closure(): TValue $factory Must not return null (null signals infrastructure failure internally)
     *
     * @return TValue
     * @noinspection PhpDocSignatureInspection*/
    public function remember(string $key, Closure $factory, int $ttl, ?Closure $validator = null): mixed
    {
        // Try cache (infrastructure failures → null)
        $cached = $this->tryGet($key);
        if (self::isValid($cached, $validator)) {
            return $cached;
        }

        // Try with lock protection (returns null on infrastructure failure)
        $result = $this->tryRefreshWithLock($key, $factory, $ttl, $validator);
        if ($result !== null) {
            return $result;
        }

        // Fallback: no lock, factory exceptions propagate naturally
        $fresh = $factory();
        $this->tryPut($key, $fresh, $ttl);

        return $fresh;
    }

    /**
     * @template TValue
     * @param-immediately-invoked-callable $factory
     * @param Closure(): TValue $factory Must not return null (null signals infrastructure failure internally)
     *
     * @return TValue
     * @throws Exception When factory fails and no stale value available
     * @noinspection PhpDocSignatureInspection*/
    public function rememberOrStale(string $key, Closure $factory, int $ttl, ?Closure $validator = null): mixed
    {
        // Try cache (infrastructure failures → null)
        $cached = $this->tryGet($key);
        if (self::isValid($cached, $validator)) {
            return $cached;
        }

        // Try with lock protection
        try {
            $result = $this->tryRefreshWithLock($key, $factory, $ttl, $validator);
            if ($result !== null) {
                return $result;
            }

            // Lock failed, try factory directly
            $fresh = $factory();
            $this->tryPut($key, $fresh, $ttl);

            return $fresh;
        } catch (Exception $e) {
            // Factory failed - fall back to stale value if available
            if ($cached !== null) {
                $this->logger->warning("{$this->serviceName} factory failed, returning stale value", [
                    'key' => $key,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                return $cached;
            }

            throw $e;
        }
    }

    // =========================================================================
    // Infrastructure Boundary Methods
    //
    // These methods encapsulate the "cache is optional" policy. They catch all
    // infrastructure failures and either return null or silently continue.
    // The error handling IS the contract - every caller expects this behavior.
    // =========================================================================

    /**
     * Attempt to read from cache, returning null on any infrastructure failure.
     */
    private function tryGet(string $key): mixed
    {
        try {
            return $this->cache->get($key);
        } catch (Throwable $e) {
            $this->logInfrastructureFailure('read', $key, $e);

            return null;
        }
    }

    /**
     * Attempt to write to cache, silently failing on infrastructure issues.
     */
    private function tryPut(string $key, mixed $value, int $ttl): void
    {
        try {
            $this->cache->put($key, $value, $ttl);
        } catch (Throwable $e) {
            $this->logInfrastructureFailure('write', $key, $e);
        }
    }

    /**
     * Attempt to refresh with lock protection.
     *
     * Infrastructure failures (lock timeout, cache read/write) return null to signal
     * "proceed without lock". Only factory callback exceptions propagate.
     */
    private function tryRefreshWithLock(
        string $key,
        Closure $factory,
        int $ttl,
        ?Closure $validator,
    ): mixed {
        // Acquire lock (infrastructure - graceful on failure)
        try {
            $lock = $this->cache->lock($key . ':lock', self::LOCK_TIMEOUT_SECONDS);
            if ($lock->block(self::LOCK_WAIT_SECONDS) !== true) {
                $this->logger->warning("{$this->serviceName} lock timeout", [
                    'key' => $key,
                    'wait_seconds' => self::LOCK_WAIT_SECONDS,
                ]);

                return null;
            }
        } catch (Throwable $e) {
            $this->logInfrastructureFailure('lock', $key, $e);

            return null;
        }

        try {
            // Double-check (infrastructure - graceful on failure)
            $cached = $this->tryGet($key);
            if (self::isValid($cached, $validator)) {
                return $cached;
            }

            // Factory execution - exceptions propagate to caller
            $fresh = $factory();

            // Cache write (infrastructure - graceful on failure)
            $this->tryPut($key, $fresh, $ttl);

            return $fresh;
        } finally {
            $lock->release();
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Check if cached value is valid using provided validator or default null check.
     */
    private static function isValid(mixed $cached, ?Closure $validator): bool
    {
        if ($cached === null) {
            return false;
        }

        if ($validator === null) {
            return true;
        }

        return (bool) $validator($cached);
    }

    /**
     * Log infrastructure failure with structured context.
     */
    private function logInfrastructureFailure(string $operation, string $key, Throwable $e): void
    {
        $this->logger->warning("{$this->serviceName} {$operation} failed", [
            'operation' => $operation,
            'key' => $key,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
