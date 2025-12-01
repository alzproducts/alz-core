<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Shopwired\Services;

use App\Application\Contracts\Shopwired\CategoryClientInterface;
use App\Application\Shopwired\Services\CachingShopwiredService;
use App\Application\Support\GracefulCache;
use App\Domain\Catalog\ValueObjects\Category;
use Closure;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CachingShopwiredService Unit Tests.
 *
 * Tests the caching decorator for ShopWired API operations.
 * Verifies correct cache keys, TTLs, callback delegation, and invalidation.
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
    | Test Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * Create a test Category domain object.
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

    /*
    |--------------------------------------------------------------------------
    | CACHE_PREFIX Constant Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function cache_prefix_constant_is_publicly_accessible(): void
    {
        $this->assertSame('shopwired', CachingShopwiredService::CACHE_PREFIX);
    }

    /*
    |--------------------------------------------------------------------------
    | getAllCategories() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_all_categories_uses_correct_cache_key(): void
    {
        $categories = [$this->createCategory(1), $this->createCategory(2)];

        $this->cache
            ->shouldReceive('remember')
            ->once()
            ->withArgs(static fn(string $key): bool => $key === 'shopwired:categories:all')
            ->andReturn($categories);

        $this->service->getAllCategories();
    }

    #[Test]
    public function get_all_categories_uses_one_hour_ttl(): void
    {
        $categories = [$this->createCategory()];

        $this->cache
            ->shouldReceive('remember')
            ->once()
            ->withArgs(static fn(string $key, int $ttl): bool => $ttl === self::ONE_HOUR)
            ->andReturn($categories);

        $this->service->getAllCategories();
    }

    #[Test]
    public function get_all_categories_callback_delegates_to_client(): void
    {
        $categories = [$this->createCategory(1), $this->createCategory(2)];

        $this->categoryClient
            ->shouldReceive('listAllCategories')
            ->once()
            ->andReturn($categories);

        $this->cache
            ->shouldReceive('remember')
            ->once()
            ->withArgs(function (string $key, int $ttl, Closure $callback) use ($categories): bool {
                // Execute the callback to verify it calls the client
                $result = $callback();
                $this->assertSame($categories, $result);

                return true;
            })
            ->andReturn($categories);

        $result = $this->service->getAllCategories();

        $this->assertSame($categories, $result);
    }

    #[Test]
    public function get_all_categories_returns_cached_data_on_hit(): void
    {
        $cachedCategories = [$this->createCategory(99)];

        $this->cache
            ->shouldReceive('remember')
            ->once()
            ->andReturn($cachedCategories);

        // Client should NOT be called on cache hit
        $this->categoryClient->shouldNotReceive('listAllCategories');

        $result = $this->service->getAllCategories();

        $this->assertSame($cachedCategories, $result);
    }

    #[Test]
    public function get_all_categories_returns_empty_array_when_no_categories(): void
    {
        $this->cache
            ->shouldReceive('remember')
            ->once()
            ->andReturn([]);

        $result = $this->service->getAllCategories();

        $this->assertSame([], $result);
    }

    /*
    |--------------------------------------------------------------------------
    | listCategories() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function list_categories_uses_correct_cache_key(): void
    {
        $categories = [$this->createCategory()];

        $this->cache
            ->shouldReceive('remember')
            ->once()
            ->withArgs(static fn(string $key): bool => $key === 'shopwired:categories:list')
            ->andReturn($categories);

        $this->service->listCategories();
    }

    #[Test]
    public function list_categories_uses_one_hour_ttl(): void
    {
        $this->cache
            ->shouldReceive('remember')
            ->once()
            ->withArgs(static fn(string $key, int $ttl): bool => $ttl === self::ONE_HOUR)
            ->andReturn([]);

        $this->service->listCategories();
    }

    #[Test]
    public function list_categories_callback_delegates_to_client(): void
    {
        $categories = [$this->createCategory(5)];

        $this->categoryClient
            ->shouldReceive('listCategories')
            ->once()
            ->andReturn($categories);

        $this->cache
            ->shouldReceive('remember')
            ->once()
            ->withArgs(function (string $key, int $ttl, Closure $callback) use ($categories): bool {
                $result = $callback();
                $this->assertSame($categories, $result);

                return true;
            })
            ->andReturn($categories);

        $result = $this->service->listCategories();

        $this->assertSame($categories, $result);
    }

    /*
    |--------------------------------------------------------------------------
    | getCategoryById() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_category_by_id_uses_correct_cache_key_with_id(): void
    {
        $categoryId = 42;
        $category = $this->createCategory($categoryId);

        $this->cache
            ->shouldReceive('remember')
            ->once()
            ->withArgs(static fn(string $key): bool => $key === 'shopwired:categories:id:42')
            ->andReturn($category);

        $this->service->getCategoryById($categoryId);
    }

    #[Test]
    public function get_category_by_id_uses_one_hour_ttl(): void
    {
        $category = $this->createCategory(1);

        $this->cache
            ->shouldReceive('remember')
            ->once()
            ->withArgs(static fn(string $key, int $ttl): bool => $ttl === self::ONE_HOUR)
            ->andReturn($category);

        $this->service->getCategoryById(1);
    }

    #[Test]
    public function get_category_by_id_callback_delegates_to_client_with_id(): void
    {
        $categoryId = 123;
        $category = $this->createCategory($categoryId);

        $this->categoryClient
            ->shouldReceive('getCategoryById')
            ->once()
            ->with($categoryId)
            ->andReturn($category);

        $this->cache
            ->shouldReceive('remember')
            ->once()
            ->withArgs(function (string $key, int $ttl, Closure $callback) use ($category): bool {
                $result = $callback();
                $this->assertSame($category, $result);

                return true;
            })
            ->andReturn($category);

        $result = $this->service->getCategoryById($categoryId);

        $this->assertSame($category, $result);
    }

    #[Test]
    public function get_category_by_id_generates_different_keys_for_different_ids(): void
    {
        $category10 = $this->createCategory(10);
        $category20 = $this->createCategory(20);

        $this->cache
            ->shouldReceive('remember')
            ->once()
            ->withArgs(static fn(string $key): bool => $key === 'shopwired:categories:id:10')
            ->andReturn($category10);

        $this->cache
            ->shouldReceive('remember')
            ->once()
            ->withArgs(static fn(string $key): bool => $key === 'shopwired:categories:id:20')
            ->andReturn($category20);

        $result10 = $this->service->getCategoryById(10);
        $result20 = $this->service->getCategoryById(20);

        $this->assertSame($category10, $result10);
        $this->assertSame($category20, $result20);
        $this->assertNotSame($result10, $result20);
    }

    /*
    |--------------------------------------------------------------------------
    | invalidateCategories() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function invalidate_categories_forgets_all_categories_key(): void
    {
        $this->cache
            ->shouldReceive('forget')
            ->once()
            ->with('shopwired:categories:all');

        $this->cache
            ->shouldReceive('forget')
            ->once()
            ->with('shopwired:categories:list');

        $this->service->invalidateCategories();
    }

    #[Test]
    public function invalidate_categories_forgets_list_categories_key(): void
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

    /*
    |--------------------------------------------------------------------------
    | invalidateAll() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function invalidate_all_delegates_to_invalidate_categories(): void
    {
        // invalidateAll() calls invalidateCategories() which calls forget() twice
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

    #[Test]
    public function invalidate_all_calls_forget_exactly_twice(): void
    {
        $forgetCallCount = 0;

        $this->cache
            ->shouldReceive('forget')
            ->twice()
            ->withArgs(static function () use (&$forgetCallCount): bool {
                $forgetCallCount++;

                return true;
            });

        $this->service->invalidateAll();

        $this->assertSame(2, $forgetCallCount);
    }
}
