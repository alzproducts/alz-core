<?php

declare(strict_types=1);

namespace App\Application\Shopwired\Services;

use App\Application\Contracts\Shopwired\CategoryClientInterface;
use App\Application\Support\CacheTimesTrait;
use App\Application\Support\GracefulCache;
use App\Domain\Catalog\ValueObjects\Category;

/**
 * Caching decorator for ShopWired API operations.
 *
 * Adds caching layer to ShopWired endpoint clients without modifying
 * the underlying implementations. Uses GracefulCache for resilient caching
 * that degrades gracefully on backend failures.
 *
 * Cache Strategy:
 * - Categories: 1 hour TTL (relatively static data, frequent reads)
 *
 * @see ShopwiredCacheClearCommand For manual cache invalidation
 */
final readonly class CachingShopwiredService
{
    use CacheTimesTrait;

    public const string CACHE_PREFIX = 'shopwired';

    private const string KEY_CATEGORIES_LIST = self::CACHE_PREFIX . ':categories:list';

    private const string KEY_CATEGORY_BY_ID = self::CACHE_PREFIX . ':categories:id:';

    public function __construct(
        private CategoryClientInterface $categoryClient,
        private GracefulCache $cache,
    ) {}

    /**
     * List all categories with caching.
     *
     * @return list<Category>
     */
    public function listCategories(): array
    {
        return $this->cache->remember(
            self::KEY_CATEGORIES_LIST,
            self::ONE_HOUR,
            fn(): array => $this->categoryClient->listCategories(),
        );
    }

    /**
     * Get a single category by ID with caching.
     */
    public function getCategoryById(int $id): Category
    {
        return $this->cache->remember(
            self::KEY_CATEGORY_BY_ID . $id,
            self::ONE_HOUR,
            fn(): Category => $this->categoryClient->getCategoryById($id),
        );
    }

    /**
     * Invalidate all category caches.
     *
     * Call when categories are modified externally or via webhooks.
     */
    public function invalidateCategories(): void
    {
        $this->cache->forget(self::KEY_CATEGORIES_LIST);
        // Individual category caches expire naturally (no ID tracking needed)
    }

    /**
     * Invalidate all ShopWired caches.
     *
     * Convenience method for clearing all service caches.
     * Extend this when additional cache keys are added.
     */
    public function invalidateAll(): void
    {
        $this->invalidateCategories();
    }
}
