<?php

declare(strict_types=1);

namespace App\Application\Support;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

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
 *
 * Note: Not marked `final` because Mockery cannot mock final classes.
 * CachingShopwiredServiceTest mocks this class directly for unit testing.
 */
class GracefulCache
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
        } catch (Throwable $e) {
            $this->logger->warning("{$this->serviceName} cache read failed", [
                'key' => $key,
                'exception' => $e->getMessage(),
            ]);
        }

        $value = $callback();

        try {
            $this->cache->set($key, $value, $ttl);
        } catch (Throwable $e) {
            $this->logger->warning("{$this->serviceName} cache write failed", [
                'key' => $key,
                'exception' => $e->getMessage(),
            ]);
        }

        return $value;
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
        } catch (Throwable $e) {
            $this->logger->warning("{$this->serviceName} cache invalidation failed", [
                'key' => $key,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
