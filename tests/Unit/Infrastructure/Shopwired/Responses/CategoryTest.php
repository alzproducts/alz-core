<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\ValueObjects\Category as DomainCategory;
use App\Domain\Catalog\ValueObjects\CategoryImage as DomainCategoryImage;
use App\Infrastructure\Shopwired\Responses\Category;
use App\Infrastructure\Shopwired\Responses\CategoryImage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Category DTO Unit Tests.
 *
 * Tests the Spatie Data DTO for parsing ShopWired category API responses.
 * Verifies snake_case mapping, nullable handling, nested objects, and domain conversion.
 */
#[CoversClass(Category::class)]
final class CategoryTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Test Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * Create a complete snake_case API payload.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function completePayload(array $overrides = []): array
    {
        return \array_merge([
            'id' => 42,
            'created_at' => '2024-01-15T10:30:00+00:00',
            'title' => 'Electronics',
            'description' => 'All electronic devices',
            'description2' => 'Extended description here',
            'slug' => 'electronics',
            'url' => 'https://shop.example.com/c/electronics',
            'active' => true,
            'featured' => true,
            'trade_only' => false,
            'sort_order' => 10,
            'meta_title' => 'Electronics - Shop',
            'meta_description' => 'Buy electronics online',
            'meta_keywords' => 'electronics, gadgets, tech',
            'meta_no_index' => false,
            'image' => null,
            'parents' => [],
        ], $overrides);
    }

    /**
     * Create a minimal payload with only required fields.
     *
     * @return array<string, mixed>
     */
    private function minimalPayload(): array
    {
        return [
            'id' => 1,
            'created_at' => '2024-01-01T00:00:00+00:00',
            'title' => 'Test',
            'description' => null,
            'description2' => null,
            'slug' => 'test',
            'url' => 'https://shop.example.com/c/test',
            'active' => true,
            'featured' => false,
            'trade_only' => false,
            'sort_order' => 0,
            'meta_title' => null,
            'meta_description' => null,
            'meta_keywords' => null,
            'meta_no_index' => false,
            'image' => null,
            'parents' => [],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | from() - Snake_case to CamelCase Mapping
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function from_maps_snake_case_to_camel_case_properties(): void
    {
        $payload = $this->completePayload([
            'created_at' => '2024-06-15T14:30:00+00:00',
            'trade_only' => true,
            'sort_order' => 25,
            'meta_title' => 'Custom Meta',
            'meta_description' => 'Custom Description',
            'meta_keywords' => 'key1, key2',
            'meta_no_index' => true,
        ]);

        $dto = Category::from($payload);

        $this->assertSame('2024-06-15T14:30:00+00:00', $dto->createdAt);
        $this->assertTrue($dto->tradeOnly);
        $this->assertSame(25, $dto->sortOrder);
        $this->assertSame('Custom Meta', $dto->metaTitle);
        $this->assertSame('Custom Description', $dto->metaDescription);
        $this->assertSame('key1, key2', $dto->metaKeywords);
        $this->assertTrue($dto->metaNoIndex);
    }

    #[Test]
    public function from_parses_integer_fields_correctly(): void
    {
        $payload = $this->completePayload([
            'id' => 999,
            'sort_order' => 100,
        ]);

        $dto = Category::from($payload);

        $this->assertSame(999, $dto->id);
        $this->assertSame(100, $dto->sortOrder);
    }

    #[Test]
    public function from_parses_boolean_fields_correctly(): void
    {
        $payload = $this->completePayload([
            'active' => false,
            'featured' => true,
            'trade_only' => true,
            'meta_no_index' => true,
        ]);

        $dto = Category::from($payload);

        $this->assertFalse($dto->active);
        $this->assertTrue($dto->featured);
        $this->assertTrue($dto->tradeOnly);
        $this->assertTrue($dto->metaNoIndex);
    }

    #[Test]
    public function from_parses_string_fields_correctly(): void
    {
        $payload = $this->completePayload([
            'title' => 'Gaming Accessories',
            'slug' => 'gaming-accessories',
            'url' => 'https://shop.example.com/c/gaming-accessories',
        ]);

        $dto = Category::from($payload);

        $this->assertSame('Gaming Accessories', $dto->title);
        $this->assertSame('gaming-accessories', $dto->slug);
        $this->assertSame('https://shop.example.com/c/gaming-accessories', $dto->url);
    }

    /*
    |--------------------------------------------------------------------------
    | from() - Nullable Field Handling
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function from_handles_null_description_fields(): void
    {
        $payload = $this->completePayload([
            'description' => null,
            'description2' => null,
        ]);

        $dto = Category::from($payload);

        $this->assertNull($dto->description);
        $this->assertNull($dto->description2);
    }

    #[Test]
    public function from_handles_null_meta_fields(): void
    {
        $payload = $this->completePayload([
            'meta_title' => null,
            'meta_description' => null,
            'meta_keywords' => null,
        ]);

        $dto = Category::from($payload);

        $this->assertNull($dto->metaTitle);
        $this->assertNull($dto->metaDescription);
        $this->assertNull($dto->metaKeywords);
    }

    #[Test]
    public function from_handles_populated_nullable_fields(): void
    {
        $payload = $this->completePayload([
            'description' => 'Main description',
            'description2' => 'Secondary description',
            'meta_title' => 'SEO Title',
            'meta_description' => 'SEO Description',
            'meta_keywords' => 'seo, keywords',
        ]);

        $dto = Category::from($payload);

        $this->assertSame('Main description', $dto->description);
        $this->assertSame('Secondary description', $dto->description2);
        $this->assertSame('SEO Title', $dto->metaTitle);
        $this->assertSame('SEO Description', $dto->metaDescription);
        $this->assertSame('seo, keywords', $dto->metaKeywords);
    }

    /*
    |--------------------------------------------------------------------------
    | from() - Nested CategoryImage Parsing
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function from_parses_nested_image_object(): void
    {
        $payload = $this->completePayload([
            'image' => ['url' => 'https://cdn.example.com/categories/electronics.jpg'],
        ]);

        $dto = Category::from($payload);

        $this->assertInstanceOf(CategoryImage::class, $dto->image);
        $this->assertSame('https://cdn.example.com/categories/electronics.jpg', $dto->image->url);
    }

    #[Test]
    public function from_handles_null_image(): void
    {
        $payload = $this->completePayload(['image' => null]);

        $dto = Category::from($payload);

        $this->assertNull($dto->image);
    }

    /*
    |--------------------------------------------------------------------------
    | from() - Recursive Parents Array Parsing
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function from_parses_empty_parents_array(): void
    {
        $payload = $this->completePayload(['parents' => []]);

        $dto = Category::from($payload);

        $this->assertSame([], $dto->parents);
    }

    #[Test]
    public function from_parses_single_parent(): void
    {
        $parentPayload = $this->minimalPayload();
        $parentPayload['id'] = 1;
        $parentPayload['title'] = 'Root Category';

        $payload = $this->completePayload(['parents' => [$parentPayload]]);

        $dto = Category::from($payload);

        $this->assertCount(1, $dto->parents);
        $this->assertInstanceOf(Category::class, $dto->parents[0]);
        $this->assertSame('Root Category', $dto->parents[0]->title);
        $this->assertSame(1, $dto->parents[0]->id);
    }

    #[Test]
    public function from_parses_multiple_parents_in_order(): void
    {
        $grandparent = $this->minimalPayload();
        $grandparent['id'] = 1;
        $grandparent['title'] = 'Root';

        $parent = $this->minimalPayload();
        $parent['id'] = 10;
        $parent['title'] = 'Electronics';

        $payload = $this->completePayload([
            'id' => 100,
            'title' => 'Laptops',
            'parents' => [$parent, $grandparent],
        ]);

        $dto = Category::from($payload);

        $this->assertCount(2, $dto->parents);
        $this->assertSame('Electronics', $dto->parents[0]->title);
        $this->assertSame('Root', $dto->parents[1]->title);
    }

    #[Test]
    public function from_parses_parents_with_images(): void
    {
        $parentPayload = $this->minimalPayload();
        $parentPayload['title'] = 'Parent with Image';
        $parentPayload['image'] = ['url' => 'https://cdn.example.com/parent.jpg'];

        $payload = $this->completePayload(['parents' => [$parentPayload]]);

        $dto = Category::from($payload);

        $this->assertNotNull($dto->parents[0]->image);
        $this->assertSame('https://cdn.example.com/parent.jpg', $dto->parents[0]->image->url);
    }

    /*
    |--------------------------------------------------------------------------
    | toDomain() - Basic Conversion
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function to_domain_returns_domain_category(): void
    {
        $dto = Category::from($this->completePayload());

        $domain = $dto->toDomain();

        $this->assertInstanceOf(DomainCategory::class, $domain);
    }

    #[Test]
    public function to_domain_maps_all_string_fields(): void
    {
        $payload = $this->completePayload([
            'title' => 'Test Title',
            'description' => 'Test Description',
            'description2' => 'Test Description 2',
            'slug' => 'test-slug',
            'url' => 'https://example.com/test',
            'meta_title' => 'Meta Title',
            'meta_description' => 'Meta Description',
            'meta_keywords' => 'Meta Keywords',
        ]);
        $dto = Category::from($payload);

        $domain = $dto->toDomain();

        $this->assertSame('Test Title', $domain->title);
        $this->assertSame('Test Description', $domain->description);
        $this->assertSame('Test Description 2', $domain->description2);
        $this->assertSame('test-slug', $domain->slug);
        $this->assertSame('https://example.com/test', $domain->url);
        $this->assertSame('Meta Title', $domain->metaTitle);
        $this->assertSame('Meta Description', $domain->metaDescription);
        $this->assertSame('Meta Keywords', $domain->metaKeywords);
    }

    #[Test]
    public function to_domain_maps_all_boolean_fields(): void
    {
        $payload = $this->completePayload([
            'active' => false,
            'featured' => true,
            'trade_only' => true,
            'meta_no_index' => true,
        ]);
        $dto = Category::from($payload);

        $domain = $dto->toDomain();

        $this->assertFalse($domain->active);
        $this->assertTrue($domain->featured);
        $this->assertTrue($domain->tradeOnly);
        $this->assertTrue($domain->metaNoIndex);
    }

    #[Test]
    public function to_domain_maps_sort_order(): void
    {
        $payload = $this->completePayload(['sort_order' => 42]);
        $dto = Category::from($payload);

        $domain = $dto->toDomain();

        $this->assertSame(42, $domain->sortOrder);
    }

    #[Test]
    public function to_domain_preserves_null_values(): void
    {
        $dto = Category::from($this->minimalPayload());

        $domain = $dto->toDomain();

        $this->assertNull($domain->description);
        $this->assertNull($domain->description2);
        $this->assertNull($domain->metaTitle);
        $this->assertNull($domain->metaDescription);
        $this->assertNull($domain->metaKeywords);
        $this->assertNull($domain->image);
    }

    /*
    |--------------------------------------------------------------------------
    | toDomain() - Nested Image Conversion
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function to_domain_converts_image_to_domain_image(): void
    {
        $payload = $this->completePayload([
            'image' => ['url' => 'https://cdn.example.com/test.jpg'],
        ]);
        $dto = Category::from($payload);

        $domain = $dto->toDomain();

        $this->assertInstanceOf(DomainCategoryImage::class, $domain->image);
        $this->assertSame('https://cdn.example.com/test.jpg', $domain->image->url);
    }

    #[Test]
    public function to_domain_keeps_null_image_as_null(): void
    {
        $payload = $this->completePayload(['image' => null]);
        $dto = Category::from($payload);

        $domain = $dto->toDomain();

        $this->assertNull($domain->image);
    }

    /*
    |--------------------------------------------------------------------------
    | toDomain() - Recursive Parents Conversion
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function to_domain_converts_empty_parents_to_empty_array(): void
    {
        $payload = $this->completePayload(['parents' => []]);
        $dto = Category::from($payload);

        $domain = $dto->toDomain();

        $this->assertSame([], $domain->parents);
    }

    #[Test]
    public function to_domain_converts_parents_to_domain_categories(): void
    {
        $parentPayload = $this->minimalPayload();
        $parentPayload['title'] = 'Parent Category';

        $payload = $this->completePayload(['parents' => [$parentPayload]]);
        $dto = Category::from($payload);

        $domain = $dto->toDomain();

        $this->assertCount(1, $domain->parents);
        $this->assertInstanceOf(DomainCategory::class, $domain->parents[0]);
        $this->assertSame('Parent Category', $domain->parents[0]->title);
    }

    #[Test]
    public function to_domain_converts_multiple_parents_preserving_order(): void
    {
        $grandparent = $this->minimalPayload();
        $grandparent['title'] = 'Grandparent';

        $parent = $this->minimalPayload();
        $parent['title'] = 'Parent';

        $payload = $this->completePayload(['parents' => [$parent, $grandparent]]);
        $dto = Category::from($payload);

        $domain = $dto->toDomain();

        $this->assertCount(2, $domain->parents);
        $this->assertSame('Parent', $domain->parents[0]->title);
        $this->assertSame('Grandparent', $domain->parents[1]->title);
    }

    #[Test]
    public function to_domain_converts_parent_images_recursively(): void
    {
        $parentPayload = $this->minimalPayload();
        $parentPayload['title'] = 'Parent with Image';
        $parentPayload['image'] = ['url' => 'https://cdn.example.com/parent-image.jpg'];

        $payload = $this->completePayload(['parents' => [$parentPayload]]);
        $dto = Category::from($payload);

        $domain = $dto->toDomain();

        $this->assertNotNull($domain->parents[0]->image);
        $this->assertInstanceOf(DomainCategoryImage::class, $domain->parents[0]->image);
        $this->assertSame('https://cdn.example.com/parent-image.jpg', $domain->parents[0]->image->url);
    }

    #[Test]
    public function to_domain_creates_new_instance_each_call(): void
    {
        $dto = Category::from($this->completePayload());

        $domain1 = $dto->toDomain();
        $domain2 = $dto->toDomain();

        $this->assertNotSame($domain1, $domain2);
        $this->assertEquals($domain1, $domain2);
    }

    /*
    |--------------------------------------------------------------------------
    | Integration: Full API Response Simulation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function complete_api_response_parses_and_converts_correctly(): void
    {
        $grandparentPayload = [
            'id' => 1,
            'created_at' => '2023-01-01T00:00:00+00:00',
            'title' => 'Home',
            'description' => null,
            'description2' => null,
            'slug' => 'home',
            'url' => 'https://shop.example.com/c/home',
            'active' => true,
            'featured' => false,
            'trade_only' => false,
            'sort_order' => 0,
            'meta_title' => null,
            'meta_description' => null,
            'meta_keywords' => null,
            'meta_no_index' => false,
            'image' => null,
            'parents' => [],
        ];

        $parentPayload = [
            'id' => 10,
            'created_at' => '2023-06-15T10:00:00+00:00',
            'title' => 'Electronics',
            'description' => 'Electronic devices',
            'description2' => null,
            'slug' => 'electronics',
            'url' => 'https://shop.example.com/c/electronics',
            'active' => true,
            'featured' => true,
            'trade_only' => false,
            'sort_order' => 5,
            'meta_title' => 'Electronics Shop',
            'meta_description' => 'Buy electronics',
            'meta_keywords' => 'electronics',
            'meta_no_index' => false,
            'image' => ['url' => 'https://cdn.example.com/electronics.jpg'],
            'parents' => [$grandparentPayload],
        ];

        $categoryPayload = [
            'id' => 100,
            'created_at' => '2024-01-15T14:30:00+00:00',
            'title' => 'Laptops',
            'description' => 'Portable computers',
            'description2' => 'Includes notebooks and ultrabooks',
            'slug' => 'laptops',
            'url' => 'https://shop.example.com/c/laptops',
            'active' => true,
            'featured' => true,
            'trade_only' => false,
            'sort_order' => 10,
            'meta_title' => 'Laptops - Shop',
            'meta_description' => 'Buy laptops online',
            'meta_keywords' => 'laptops, notebooks',
            'meta_no_index' => false,
            'image' => ['url' => 'https://cdn.example.com/laptops.jpg'],
            'parents' => [$parentPayload, $grandparentPayload],
        ];

        $dto = Category::from($categoryPayload);
        $domain = $dto->toDomain();

        // Verify main category
        $this->assertSame('Laptops', $domain->title);
        $this->assertSame('Portable computers', $domain->description);
        $this->assertSame('https://cdn.example.com/laptops.jpg', $domain->image->url);

        // Verify parent chain
        $this->assertCount(2, $domain->parents);
        $this->assertSame('Electronics', $domain->parents[0]->title);
        $this->assertSame('https://cdn.example.com/electronics.jpg', $domain->parents[0]->image->url);
        $this->assertSame('Home', $domain->parents[1]->title);
        $this->assertNull($domain->parents[1]->image);
    }
}
