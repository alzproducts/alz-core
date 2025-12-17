<?php

declare(strict_types=1);

namespace App\Application\Support;

use Closure;
use Exception;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheException;
use Psr\SimpleCache\CacheInterface;

/**
 * Cache wrapper with graceful degradation on failures.
 *
 * Wraps PSR-16 SimpleCache to provide resilient caching that doesn't
 * break application flow when the cache backend is unavailable.
 *
 * Degradation behavior:
 * - Read failure: Treats as cache miss, executes callback
 * - Write failure: Logs warning, returns fresh data anyway
 * - Delete failure: Logs warning, cache expires naturally
 *
 * Use this for caching that improves performance but isn't required
 * for correctness. The application continues working if cache fails.
 */
final readonly class GracefulCache
{
    public function __construct(
        private CacheInterface $cache,
        private LoggerInterface $logger,
        private string $serviceName = 'cache',
    ) {}

    /**
     * Get value from cache or execute callback and cache result.
     * @template T
     * @param-immediately-invoked-callable $callback
     *
     * @param Closure(): T $callback
     *
     * @return T
     * @noinspection PhpDocSignatureInspection*/
    public function remember(string $key, int $ttl, Closure $callback): mixed
    {
        try {
            $cached = $this->cache->get($key);

            if ($cached !== null) {
                /** @var T $cached */
                return $cached;
            }
        } catch (CacheException|Exception $e) { // @ignoreException - graceful degradation: treat as cache miss
            $this->logger->warning("{$this->serviceName} cache read failed", [
                'key' => $key,
                'exception' => $e->getMessage(),
            ]);
        }

        $value = $callback();

        try {
            $this->cache->set($key, $value, $ttl);
        } catch (CacheException|Exception $e) { // @ignoreException - graceful degradation: return fresh data anyway
            $this->logger->warning("{$this->serviceName} cache write failed", [
                'key' => $key,
                'exception' => $e->getMessage(),
            ]);
        }

        return $value;
    }

    /**
     * Get integer value from cache or execute callback and cache result.
     * Redis serializes integers as strings. This method ensures type-safe
     * integer retrieval by casting the cached value back to int.
     * @param-immediately-invoked-callable $callback
     *
     * @param Closure(): ?int $callback
     *
     * @noinspection PhpUnused*/
    public function rememberInt(string $key, int $ttl, Closure $callback): ?int
    {
        $value = $this->remember($key, $ttl, $callback);

        // Redis serializes integers as strings (Laravel issue #31345)
        // @phpstan-ignore cast.useless
        return ($value === null) ? null : (int) $value;
    }

    /**
     * Get a value from cache.
     *
     * Degrades gracefully - read failures return null (treated as cache miss).
     */
    public function get(string $key): mixed
    {
        try {
            return $this->cache->get($key);
        } catch (CacheException|Exception $e) { // @ignoreException - graceful degradation: treat as cache miss
            $this->logger->warning("{$this->serviceName} cache read failed", [
                'key' => $key,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Put a value in cache with TTL.
     *
     * Degrades gracefully - write failures are logged but don't throw.
     */
    public function put(string $key, mixed $value, int $ttl): void
    {
        try {
            $this->cache->set($key, $value, $ttl);
        } catch (CacheException|Exception $e) { // @ignoreException - graceful degradation: silent failure is acceptable
            $this->logger->warning("{$this->serviceName} cache write failed", [
                'key' => $key,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Remove a value from cache.
     *
     * Degrades gracefully - deletion failures are logged but don't throw.
     * The cache will naturally expire or be overwritten on next fetch.
     */
    public function forget(string $key): void
    {
        try {
            $this->cache->delete($key);
        } catch (CacheException|Exception $e) { // @ignoreException - graceful degradation: cache expires naturally
            $this->logger->warning("{$this->serviceName} cache invalidation failed", [
                'key' => $key,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
