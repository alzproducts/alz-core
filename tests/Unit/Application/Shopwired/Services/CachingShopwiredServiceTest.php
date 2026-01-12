<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Shopwired\Services;

use App\Application\Contracts\Shopwired\CategoryClientInterface;
use App\Application\Shopwired\Services\CachingShopwiredService;
use App\Application\Support\GracefulCache;
use App\Domain\Catalog\ValueObjects\Category;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CachingShopwiredService Unit Tests.
 *
 * Focus: TTL values and cache invalidation behavior.
 * Not tested: Cache key patterns, delegation verification (covered by integration tests).
 */
#[CoversClass(CachingShopwiredService::class)]
final class CachingShopwiredServiceTest extends TestCase
{
    private const int ONE_HOUR = 3600;

    private MockInterface&CategoryClientInterface $categoryClient;

    private MockInterface&GracefulCache $cache;

    private CachingShopwiredService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoryClient = Mockery::mock(CategoryClientInterface::class);
        $this->cache = Mockery::mock(GracefulCache::class);
        $this->service = new CachingShopwiredService($this->categoryClient, $this->cache);
    }

    /*
    |--------------------------------------------------------------------------
    | TTL Verification Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_all_categories_caches_for_one_hour(): void
    {
        $categories = [$this->createCategory()];

        $this->cache
            ->shouldReceive('remember')
            ->once()
            ->withArgs(static fn(string $key, int $ttl): bool => $ttl === self::ONE_HOUR)
            ->andReturn($categories);

        $result = $this->service->getAllCategories();

        $this->assertCount(1, $result);
    }

    #[Test]
    public function list_categories_caches_for_one_hour(): void
    {
        $this->cache
            ->shouldReceive('remember')
            ->once()
            ->withArgs(static fn(string $key, int $ttl): bool => $ttl === self::ONE_HOUR)
            ->andReturn([]);

        $this->service->listCategories();
    }

    #[Test]
    public function get_category_by_id_caches_for_one_hour(): void
    {
        $category = $this->createCategory(42);

        $this->cache
            ->shouldReceive('remember')
            ->once()
            ->withArgs(static fn(string $key, int $ttl): bool => $ttl === self::ONE_HOUR)
            ->andReturn($category);

        $result = $this->service->getCategoryById(42);

        $this->assertSame('category-42', $result->slug);
    }

    /*
    |--------------------------------------------------------------------------
    | Cache Invalidation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function invalidate_categories_clears_both_cache_keys(): void
    {
        $forgottenKeys = [];

        $this->cache
            ->shouldReceive('forget')
            ->twice()
            ->withArgs(static function (string $key) use (&$forgottenKeys): bool {
                $forgottenKeys[] = $key;

                return true;
            });

        $this->service->invalidateCategories();

        $this->assertContains('shopwired:categories:all', $forgottenKeys);
        $this->assertContains('shopwired:categories:list', $forgottenKeys);
    }

    #[Test]
    public function invalidate_all_clears_category_caches(): void
    {
        $this->cache
            ->shouldReceive('forget')
            ->once()
            ->with('shopwired:categories:all');

        $this->cache
            ->shouldReceive('forget')
            ->once()
            ->with('shopwired:categories:list');

        $this->service->invalidateAll();
    }

    /*
    |--------------------------------------------------------------------------
    | Test Helpers
    |--------------------------------------------------------------------------
    */

    private function createCategory(int $id = 1, string $title = 'Test Category'): Category
    {
        return new Category(
            title: $title,
            description: null,
            description2: null,
            slug: "category-{$id}",
            url: "https://shop.example.com/c/category-{$id}",
            active: true,
            featured: false,
            tradeOnly: false,
            sortOrder: $id,
            metaTitle: null,
            metaDescription: null,
            metaKeywords: null,
            metaNoIndex: false,
            image: null,
            parents: [],
        );
    }
}
