<?php

declare(strict_types=1);

namespace App\Infrastructure\Support;

use App\Application\Contracts\LockableCacheInterface;
use Closure;
use Exception;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Lock;
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
        } catch (Throwable $e) { // @ignoreException - graceful degradation: cache expires naturally
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

        return $this->refreshValue($key, $factory, $ttl, $validator);
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
        $cached = $this->tryGet($key);
        if (self::isValid($cached, $validator)) {
            return $cached;
        }

        try {
            return $this->refreshValue($key, $factory, $ttl, $validator);
        } catch (Exception $e) {
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
        } catch (Throwable $e) { // @ignoreException - graceful degradation: treat as cache miss
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
        } catch (Throwable $e) { // @ignoreException - graceful degradation: silent failure is acceptable
            $this->logInfrastructureFailure('write', $key, $e);
        }
    }

    /**
     * Refresh the cached value, trying lock-protected path first, then direct factory.
     *
     * Factory exceptions always propagate to the caller.
     */
    private function refreshValue(
        string $key,
        Closure $factory,
        int $ttl,
        ?Closure $validator,
    ): mixed {
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
     * Attempt to refresh with lock protection.
     *
     * Infrastructure failures (lock timeout, cache read/write) return null to signal
     * "proceed without lock". Only factory callback exceptions propagate.
     */
    private function tryRefreshWithLock(string $key, Closure $factory, int $ttl, ?Closure $validator): mixed
    {
        $lock = $this->acquireLock($key);
        if ($lock === null) {
            return null;
        }
        try {
            // Double-check after lock acquisition
            $cached = $this->tryGet($key);
            if (self::isValid($cached, $validator)) {
                return $cached;
            }
            $fresh = $factory();
            $this->tryPut($key, $fresh, $ttl);
            return $fresh;
        } finally {
            $lock->release();
        }
    }

    /**
     * Attempt to acquire a cache lock, returning null on infrastructure failure.
     */
    private function acquireLock(string $key): ?Lock
    {
        try {
            $lock = $this->cache->lock($key . ':lock', self::LOCK_TIMEOUT_SECONDS);

            if ($lock->block(self::LOCK_WAIT_SECONDS) !== true) {
                $this->logger->warning("{$this->serviceName} lock timeout", [
                    'key' => $key,
                    'wait_seconds' => self::LOCK_WAIT_SECONDS,
                ]);

                return null;
            }

            return $lock;
        } catch (Throwable $e) { // @ignoreException - graceful degradation: proceed without lock protection
            $this->logInfrastructureFailure('lock', $key, $e);

            return null;
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
