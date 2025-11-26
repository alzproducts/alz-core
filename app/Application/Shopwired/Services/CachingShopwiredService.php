<?php

declare(strict_types=1);

namespace App\Application\Shopwired\Services;

use App\Application\Support\CacheTimesTrait;

/**
 * Caching decorator for ShopWired API operations.
 *
 * Adds caching layer to ShopwiredClientInterface without modifying
 * the underlying client. Uses GracefulCache for resilient caching
 * that degrades gracefully on backend failures.
 *
 * @see ShopwiredCacheClearCommand For manual cache invalidation
 */
final readonly class CachingShopwiredService
{
    use CacheTimesTrait;

    public const string CACHE_PREFIX = 'shopwired';

    /**
     * Invalidate all ShopWired caches.
     *
     * Convenience method for clearing all service caches.
     * Extend this when additional cache keys are added.
     */
    public function invalidateAll(): void
    {
        // Add cache invalidation calls here as endpoints are added
    }
}
