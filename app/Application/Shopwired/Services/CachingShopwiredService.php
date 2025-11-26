<?php

declare(strict_types=1);

namespace App\Application\Shopwired\Services;

use App\Application\Contracts\ShopwiredClientInterface;
use App\Application\Support\CacheTimesTrait;
use App\Domain\Order\ValueObjects\PaymentMethod;
use Closure;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Caching decorator for ShopWired API operations.
 *
 * Adds caching layer to ShopwiredClientInterface without modifying
 * the underlying client. Uses PSR-16 SimpleCache for framework independence.
 *
 * Cache operations degrade gracefully - failures are logged but don't
 * prevent API operations from succeeding. This ensures the service
 * remains functional even if the cache backend is temporarily unavailable.
 *
 * Cache TTLs:
 * - Payment methods: 7 days (rarely changes, manual invalidation available)
 *
 * @see ShopwiredCacheClearCommand For manual cache invalidation
 */
final readonly class CachingShopwiredService
{
    use CacheTimesTrait;

    public const string CACHE_PREFIX = 'shopwired';

    private const string KEY_PAYMENT_METHODS = self::CACHE_PREFIX . ':payment-methods';

    public function __construct(
        private ShopwiredClientInterface $client,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {}

    /**
     * Get payment methods with caching.
     *
     * @return list<PaymentMethod>
     */
    public function getPaymentMethods(): array
    {
        /** @var list<PaymentMethod> */
        return $this->remember(
            self::KEY_PAYMENT_METHODS,
            self::SEVEN_DAYS,
            fn(): array => $this->client->listPaymentMethods(),
        );
    }

    /**
     * Invalidate payment methods cache.
     *
     * Call this when payment methods are updated externally
     * or when fresh data is needed.
     */
    public function invalidatePaymentMethods(): void
    {
        $this->forget(self::KEY_PAYMENT_METHODS);
    }

    /**
     * Invalidate all ShopWired caches.
     *
     * Convenience method for clearing all service caches.
     * Extend this when additional cache keys are added.
     */
    public function invalidateAll(): void
    {
        $this->invalidatePaymentMethods();
    }

    /**
     * Get value from cache or execute callback and cache result.
     *
     * Degrades gracefully on cache failures:
     * - Read failure: executes callback (cache miss behavior)
     * - Write failure: logs warning, returns fresh data anyway
     *
     * @template T
     *
     * @param-immediately-invoked-callable $callback
     * @param Closure(): T $callback
     *
     * @return T
     */
    private function remember(string $key, int $ttl, Closure $callback): mixed
    {
        try {
            $cached = $this->cache->get($key);

            if ($cached !== null) {
                /** @var T $cached */
                return $cached;
            }
        } catch (Throwable $e) {
            $this->logger->warning('Shopwired cache read failed', [
                'key' => $key,
                'exception' => $e->getMessage(),
            ]);
        }

        $value = $callback();

        try {
            $this->cache->set($key, $value, $ttl);
        } catch (Throwable $e) {
            $this->logger->warning('Shopwired cache write failed', [
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
    private function forget(string $key): void
    {
        try {
            $this->cache->delete($key);
        } catch (Throwable $e) {
            $this->logger->warning('Shopwired cache invalidation failed', [
                'key' => $key,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
