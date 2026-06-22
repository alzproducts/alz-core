<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use Closure;

/**
 * Cache interface with graceful degradation on failures.
 *
 * All operations degrade gracefully — cache failures are logged,
 * never thrown. The application continues working if cache is down.
 *
 * Tag support:
 * - Pass a tag to group related keys for bulk invalidation via flushTag().
 * - Tags require a taggable backend (Redis, Memcached). On non-taggable
 *   backends (array, file), tagged operations gracefully degrade to no-cache.
 * - A key's tag must be consistent across all operations (read/write/delete).
 */
interface ResilientCacheInterface
{
    /**
     * Get value from cache or execute callback and cache result.
     *
     * @template T
     * @param-immediately-invoked-callable $callback
     *
     * @param Closure(): T $callback
     *
     * @return T
     */
    public function remember(string $key, int $ttl, Closure $callback, ?string $tag = null): mixed;

    /**
     * Get integer value from cache or execute callback and cache result.
     *
     * Redis serializes integers as strings. This method ensures type-safe
     * integer retrieval by casting the cached value back to int.
     *
     * @param-immediately-invoked-callable $callback
     *
     * @param Closure(): ?int $callback
     */
    public function rememberInt(string $key, int $ttl, Closure $callback, ?string $tag = null): ?int;

    /**
     * Get a value from cache.
     *
     * Read failures return null (treated as cache miss).
     */
    public function get(string $key, ?string $tag = null): mixed;

    /**
     * Put a value in cache with TTL.
     *
     * Write failures are logged but don't throw.
     */
    public function put(string $key, mixed $value, int $ttl, ?string $tag = null): void;

    /**
     * Remove a value from cache.
     *
     * Deletion failures are logged but don't throw.
     */
    public function forget(string $key, ?string $tag = null): void;

    /**
     * Flush all cache entries for a tag.
     *
     * On non-taggable backends, this is a no-op (logged).
     */
    public function flushTag(string $tag): void;
}
