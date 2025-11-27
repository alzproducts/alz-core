<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired\Clients;

use App\Domain\Catalog\ValueObjects\Category as DomainCategory;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiResponseException;
use App\Infrastructure\Shopwired\Clients\CategoryClient;
use App\Infrastructure\Shopwired\ShopwiredHttpTransport;
use App\Infrastructure\Shopwired\ShopwiredResponseParserTrait;
use Illuminate\Http\Client\Response;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CategoryClient Unit Tests.
 *
 * Tests the ShopWired Categories API client functionality.
 * Covers endpoint routing, response parsing, domain conversion, and pagination.
 */
#[CoversClass(CategoryClient::class)]
#[CoversClass(ShopwiredResponseParserTrait::class)]
final class CategoryClientTest extends TestCase
{
    private MockInterface&ShopwiredHttpTransport $transport;

    private CategoryClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = Mockery::mock(ShopwiredHttpTransport::class);
        $this->client = new CategoryClient($this->transport);
    }

    /*
    |--------------------------------------------------------------------------
    | Test Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * Create a mock Response returning the given JSON data.
     *
     * @param array<mixed>|null $data
     */
    private function mockResponse(?array $data): MockInterface&Response
    {
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->andReturn($data);

        return $response;
    }

    /**
     * Generate realistic category API payload.
     *
     * @param list<array<string, mixed>> $parents
     *
     * @return array<string, mixed>
     */
    private function categoryPayload(int $id, string $title, array $parents = []): array
    {
        return [
            'id' => $id,
            'created_at' => '2024-01-15T10:30:00+00:00',
            'title' => $title,
            'description' => "Description for {$title}",
            'description2' => null,
            'slug' => \mb_strtolower(\str_replace(' ', '-', $title)),
            'url' => 'https://shop.example.com/c/' . \mb_strtolower(\str_replace(' ', '-', $title)),
            'active' => true,
            'featured' => false,
            'trade_only' => false,
            'sort_order' => 10,
            'meta_title' => $title,
            'meta_description' => "Buy {$title} products",
            'meta_keywords' => null,
            'meta_no_index' => false,
            'image' => null,
            'parents' => $parents,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | listCategories() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function list_categories_calls_correct_endpoint(): void
    {
        $payload = [
            $this->categoryPayload(1, 'Electronics'),
            $this->categoryPayload(2, 'Clothing'),
        ];

        $this->transport
            ->shouldReceive('get')
            ->once()
            ->with('categories')
            ->andReturn($this->mockResponse($payload));

        $this->client->listCategories();
    }

    #[Test]
    public function list_categories_returns_domain_objects_with_correct_values(): void
    {
        $payload = [
            $this->categoryPayload(1, 'Electronics'),
            $this->categoryPayload(2, 'Clothing'),
        ];

        $this->transport
            ->shouldReceive('get')
            ->with('categories')
            ->andReturn($this->mockResponse($payload));

        $result = $this->client->listCategories();

        $this->assertCount(2, $result);
        $this->assertInstanceOf(DomainCategory::class, $result[0]);
        $this->assertInstanceOf(DomainCategory::class, $result[1]);
        $this->assertSame('Electronics', $result[0]->title);
        $this->assertSame('Clothing', $result[1]->title);
        $this->assertSame('electronics', $result[0]->slug);
        $this->assertTrue($result[0]->active);
    }

    #[Test]
    public function list_categories_returns_empty_array_when_api_returns_empty(): void
    {
        $this->transport
            ->shouldReceive('get')
            ->with('categories')
            ->andReturn($this->mockResponse([]));

        $result = $this->client->listCategories();

        $this->assertSame([], $result);
    }

    #[Test]
    public function list_categories_throws_on_non_array_response(): void
    {
        $this->transport
            ->shouldReceive('get')
            ->with('categories')
            ->andReturn($this->mockResponse(null));

        $this->expectException(InvalidApiResponseException::class);

        $this->client->listCategories();
    }

    /*
    |--------------------------------------------------------------------------
    | getCategoryById() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_category_by_id_calls_correct_endpoint_with_id(): void
    {
        $categoryId = 42;
        $payload = $this->categoryPayload($categoryId, 'Laptops');

        $this->transport
            ->shouldReceive('get')
            ->once()
            ->with('categories/42')
            ->andReturn($this->mockResponse($payload));

        $this->client->getCategoryById($categoryId);
    }

    #[Test]
    public function get_category_by_id_returns_domain_object_with_correct_values(): void
    {
        $payload = $this->categoryPayload(99, 'Gaming Mice');

        $this->transport
            ->shouldReceive('get')
            ->with('categories/99')
            ->andReturn($this->mockResponse($payload));

        $result = $this->client->getCategoryById(99);

        $this->assertInstanceOf(DomainCategory::class, $result);
        $this->assertSame('Gaming Mice', $result->title);
        $this->assertSame('gaming-mice', $result->slug);
        $this->assertSame('Buy Gaming Mice products', $result->metaDescription);
    }

    #[Test]
    public function get_category_by_id_returns_object_with_embedded_parents(): void
    {
        $grandparent = $this->categoryPayload(1, 'Electronics');
        $parent = $this->categoryPayload(10, 'Computers', [$grandparent]);
        $category = $this->categoryPayload(100, 'Laptops', [$parent, $grandparent]);

        $this->transport
            ->shouldReceive('get')
            ->with('categories/100')
            ->andReturn($this->mockResponse($category));

        $result = $this->client->getCategoryById(100);

        $this->assertSame('Laptops', $result->title);
        $this->assertCount(2, $result->parents);
        $this->assertSame('Computers', $result->parents[0]->title);
        $this->assertSame('Electronics', $result->parents[1]->title);
    }

    #[Test]
    public function get_category_by_id_propagates_transport_exception(): void
    {
        $this->transport
            ->shouldReceive('get')
            ->with('categories/404')
            ->andThrow(new ExternalServiceUnavailableException('Shopwired'));

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->client->getCategoryById(404);
    }

    #[Test]
    public function get_category_by_id_throws_on_non_array_response(): void
    {
        $this->transport
            ->shouldReceive('get')
            ->with('categories/1')
            ->andReturn($this->mockResponse(null));

        $this->expectException(InvalidApiResponseException::class);

        $this->client->getCategoryById(1);
    }

    /*
    |--------------------------------------------------------------------------
    | getCategoryCount() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_category_count_calls_count_endpoint(): void
    {
        $this->transport
            ->shouldReceive('get')
            ->once()
            ->with('categories/count')
            ->andReturn($this->mockResponse(['count' => 150]));

        $this->client->getCategoryCount();
    }

    #[Test]
    public function get_category_count_returns_integer_from_response(): void
    {
        $this->transport
            ->shouldReceive('get')
            ->with('categories/count')
            ->andReturn($this->mockResponse(['count' => 42]));

        $result = $this->client->getCategoryCount();

        $this->assertSame(42, $result);
    }

    #[Test]
    public function get_category_count_returns_zero_when_no_categories(): void
    {
        $this->transport
            ->shouldReceive('get')
            ->with('categories/count')
            ->andReturn($this->mockResponse(['count' => 0]));

        $result = $this->client->getCategoryCount();

        $this->assertSame(0, $result);
    }

    #[Test]
    #[DataProvider('invalidCountResponses')]
    public function get_category_count_throws_on_invalid_response(mixed $response): void
    {
        $this->transport
            ->shouldReceive('get')
            ->with('categories/count')
            ->andReturn($this->mockResponse($response));

        $this->expectException(InvalidApiResponseException::class);

        $this->client->getCategoryCount();
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidCountResponses(): array
    {
        return [
            'null response' => [null],
            'empty array' => [[]],
            'missing count key' => [['total' => 5]],
            'count is null' => [['count' => null]],
            'count is string' => [['count' => '42']],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | listAllCategories() Tests - Pagination
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function list_all_categories_sends_correct_pagination_params(): void
    {
        $this->transport
            ->shouldReceive('get')
            ->once()
            ->with('categories', [
                'count' => 100,
                'offset' => 0,
                'embed' => 'parents',
            ])
            ->andReturn($this->mockResponse([]));

        $this->client->listAllCategories();
    }

    #[Test]
    public function list_all_categories_returns_empty_when_first_page_empty(): void
    {
        $this->transport
            ->shouldReceive('get')
            ->with('categories', Mockery::type('array'))
            ->andReturn($this->mockResponse([]));

        $result = $this->client->listAllCategories();

        $this->assertSame([], $result);
    }

    #[Test]
    public function list_all_categories_returns_single_page_when_under_page_size(): void
    {
        $payload = [
            $this->categoryPayload(1, 'Electronics'),
            $this->categoryPayload(2, 'Clothing'),
        ];

        $this->transport
            ->shouldReceive('get')
            ->once()
            ->with('categories', [
                'count' => 100,
                'offset' => 0,
                'embed' => 'parents',
            ])
            ->andReturn($this->mockResponse($payload));

        $result = $this->client->listAllCategories();

        $this->assertCount(2, $result);
        $this->assertSame('Electronics', $result[0]->title);
        $this->assertSame('Clothing', $result[1]->title);
    }

    #[Test]
    public function list_all_categories_fetches_multiple_pages(): void
    {
        // Page 1: 100 items (full page)
        $page1 = \array_map(
            fn(int $i) => $this->categoryPayload($i, "Category {$i}"),
            \range(1, 100),
        );

        // Page 2: 30 items (partial - stops pagination)
        $page2 = \array_map(
            fn(int $i) => $this->categoryPayload($i, "Category {$i}"),
            \range(101, 130),
        );

        $this->transport
            ->shouldReceive('get')
            ->once()
            ->with('categories', [
                'count' => 100,
                'offset' => 0,
                'embed' => 'parents',
            ])
            ->andReturn($this->mockResponse($page1));

        $this->transport
            ->shouldReceive('get')
            ->once()
            ->with('categories', [
                'count' => 100,
                'offset' => 100,
                'embed' => 'parents',
            ])
            ->andReturn($this->mockResponse($page2));

        $result = $this->client->listAllCategories();

        $this->assertCount(130, $result);
        $this->assertSame('Category 1', $result[0]->title);
        $this->assertSame('Category 100', $result[99]->title);
        $this->assertSame('Category 101', $result[100]->title);
        $this->assertSame('Category 130', $result[129]->title);
    }

    #[Test]
    public function list_all_categories_checks_next_page_when_exactly_full(): void
    {
        // Page 1: Exactly 100 items
        $page1 = \array_map(
            fn(int $i) => $this->categoryPayload($i, "Category {$i}"),
            \range(1, 100),
        );

        // Page 2: Empty (terminates pagination)
        $page2 = [];

        $this->transport
            ->shouldReceive('get')
            ->once()
            ->with('categories', [
                'count' => 100,
                'offset' => 0,
                'embed' => 'parents',
            ])
            ->andReturn($this->mockResponse($page1));

        $this->transport
            ->shouldReceive('get')
            ->once()
            ->with('categories', [
                'count' => 100,
                'offset' => 100,
                'embed' => 'parents',
            ])
            ->andReturn($this->mockResponse($page2));

        $result = $this->client->listAllCategories();

        $this->assertCount(100, $result);
    }

    #[Test]
    public function list_all_categories_preserves_domain_object_types(): void
    {
        $parentPayload = $this->categoryPayload(1, 'Electronics');
        $categoryPayload = $this->categoryPayload(10, 'Laptops', [$parentPayload]);

        $this->transport
            ->shouldReceive('get')
            ->with('categories', Mockery::type('array'))
            ->andReturn($this->mockResponse([$categoryPayload]));

        $result = $this->client->listAllCategories();

        $this->assertCount(1, $result);
        $this->assertInstanceOf(DomainCategory::class, $result[0]);
        $this->assertSame('Laptops', $result[0]->title);
        $this->assertCount(1, $result[0]->parents);
        $this->assertInstanceOf(DomainCategory::class, $result[0]->parents[0]);
        $this->assertSame('Electronics', $result[0]->parents[0]->title);
    }

    /*
    |--------------------------------------------------------------------------
    | Category with Image Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function category_with_image_converts_to_domain_image(): void
    {
        $payload = $this->categoryPayload(1, 'Featured');
        $payload['image'] = ['url' => 'https://cdn.example.com/images/featured.jpg'];

        $this->transport
            ->shouldReceive('get')
            ->with('categories/1')
            ->andReturn($this->mockResponse($payload));

        $result = $this->client->getCategoryById(1);

        $this->assertNotNull($result->image);
        $this->assertSame('https://cdn.example.com/images/featured.jpg', $result->image->url);
    }

    #[Test]
    public function category_without_image_has_null_image(): void
    {
        $payload = $this->categoryPayload(1, 'No Image');
        $payload['image'] = null;

        $this->transport
            ->shouldReceive('get')
            ->with('categories/1')
            ->andReturn($this->mockResponse($payload));

        $result = $this->client->getCategoryById(1);

        $this->assertNull($result->image);
    }

    /*
    |--------------------------------------------------------------------------
    | CannotCreateData Exception Tests (API Contract Violations)
    |--------------------------------------------------------------------------
    | These tests cover the catch(CannotCreateData) paths in ShopwiredResponseParserTrait.
    | They simulate API contract changes where the response is a valid array
    | but the structure doesn't match the expected DTO schema.
    */

    #[Test]
    public function list_categories_throws_on_malformed_array_items(): void
    {
        // Array is valid, but items have wrong structure (missing required 'id' field)
        $malformedPayload = [
            ['title' => 'Category Without ID'], // Missing id, created_at, slug, url, etc.
        ];

        $this->transport
            ->shouldReceive('get')
            ->with('categories')
            ->andReturn($this->mockResponse($malformedPayload));

        $this->expectException(InvalidApiResponseException::class);
        $this->expectExceptionMessage('API returned invalid data structure');

        $this->client->listCategories();
    }

    #[Test]
    public function get_category_by_id_throws_on_malformed_single_response(): void
    {
        // Response is array (passes is_array check) but missing required fields
        $malformedPayload = [
            'title' => 'Incomplete Category',
            // Missing: id, created_at, slug, url, active, featured, etc.
        ];

        $this->transport
            ->shouldReceive('get')
            ->with('categories/1')
            ->andReturn($this->mockResponse($malformedPayload));

        $this->expectException(InvalidApiResponseException::class);
        $this->expectExceptionMessage('API returned invalid data structure');

        $this->client->getCategoryById(1);
    }

    #[Test]
    public function list_all_categories_throws_on_malformed_paginated_response(): void
    {
        // First page returns malformed data
        $malformedPayload = [
            ['invalid' => 'structure'],
        ];

        $this->transport
            ->shouldReceive('get')
            ->with('categories', Mockery::type('array'))
            ->andReturn($this->mockResponse($malformedPayload));

        $this->expectException(InvalidApiResponseException::class);
        $this->expectExceptionMessage('API returned invalid data structure');

        $this->client->listAllCategories();
    }
}
