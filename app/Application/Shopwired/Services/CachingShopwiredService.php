<?php

declare(strict_types=1);

namespace App\Application\Shopwired\Services;

use App\Application\Contracts\ShopwiredClientInterface;
use App\Application\Support\CacheTimesTrait;
use App\Application\Support\GracefulCache;
use App\Domain\Order\ValueObjects\PaymentMethod;

/**
 * Caching decorator for ShopWired API operations.
 *
 * Adds caching layer to ShopwiredClientInterface without modifying
 * the underlying client. Uses GracefulCache for resilient caching
 * that degrades gracefully on backend failures.
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
        private GracefulCache $cache,
    ) {}

    /**
     * Get payment methods with caching.
     *
     * @return list<PaymentMethod>
     */
    public function getPaymentMethods(): array
    {
        /** @var list<PaymentMethod> */
        return $this->cache->remember(
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
        $this->cache->forget(self::KEY_PAYMENT_METHODS);
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
}
