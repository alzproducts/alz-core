<?php

/** @noinspection ALL */

declare(strict_types=1);

namespace App\Application\Contracts;

use Closure;
use Exception;

/**
 * Cache interface with atomic locking for concurrent-safe refresh operations.
 *
 * Use this for cached values that:
 * - Are expensive to compute (API calls, OAuth tokens)
 * - Need thundering herd protection (only one process refreshes)
 * - May have internal validity beyond cache TTL (e.g., token expiry)
 *
 * Graceful degradation:
 * - Cache read/write failures are logged, not thrown
 * - Lock timeouts are logged, refresh proceeds without lock protection
 * - Only factory callback exceptions propagate to caller
 */
interface LockableCacheInterface
{
    /**
     * Remove a value from cache.
     *
     * Graceful: deletion failures are logged but don't throw.
     * The cache will naturally expire or be overwritten on next refresh.
     */
    public function forget(string $key): void;

    /**
     * Get from cache or execute factory with lock protection.
     *
     * Pattern:
     * 1. Read from cache
     * 2. If valid (passes validator) → return cached value
     * 3. Acquire lock (prevents thundering herd)
     * 4. Double-check cache (another process may have refreshed)
     * 5. Execute factory callback
     * 6. Store result in cache
     * 7. Release lock
     *
     * If lock acquisition times out, refresh proceeds without lock (logged).
     *
     * IMPORTANT: Factory must return non-null. Null is used internally to signal
     * infrastructure failures (cache miss, lock timeout). A factory returning null
     * will trigger redundant factory calls and incorrect stale fallback behavior.
     *
     * @template TValue
     *
     * @param string $key Cache key
     * @param-immediately-invoked-callable $factory
     * @param Closure(): TValue $factory Produces fresh value when cache invalid. Must not return null.
     * @param int $ttl Cache TTL in seconds
     * @param (Closure(mixed): bool)|null $validator Optional. Returns true if cached value is still valid.
     *                                               Defaults to "not null" check if omitted.
     *
     * @return TValue Fresh or cached value
     */
    public function remember(string $key, Closure $factory, int $ttl, ?Closure $validator = null): mixed;

    /**
     * Get from cache or execute factory, falling back to stale value on failure.
     *
     * Same as remember(), but if the factory callback throws:
     * - If stale cached value exists → log warning, return stale value
     * - If no cached value exists → rethrow exception
     *
     * Use this when a stale value is better than an error (graceful degradation).
     *
     * IMPORTANT: Factory must return non-null. See remember() for rationale.
     *
     * @template TValue
     *
     * @param string $key Cache key
     * @param-immediately-invoked-callable $factory
     * @param Closure(): TValue $factory Produces fresh value when cache invalid. Must not return null.
     * @param int $ttl Cache TTL in seconds
     * @param (Closure(mixed): bool)|null $validator Optional. Returns true if cached value is still valid.
     *                                               Defaults to "not null" check if omitted.
     *
     * @return TValue Fresh, cached, or stale value
     *
     * @throws Exception When factory fails and no stale value available
     */
    public function rememberOrStale(string $key, Closure $factory, int $ttl, ?Closure $validator = null): mixed;
}
