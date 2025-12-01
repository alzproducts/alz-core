<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\ValueObjects;

use App\Domain\Catalog\ValueObjects\Category;
use App\Domain\Catalog\ValueObjects\CategoryImage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Category::class)]
final class CategoryTest extends TestCase
{
    #[Test]
    public function it_constructs_with_all_properties_on_happy_path(): void
    {
        // Arrange
        $image = new CategoryImage('https://example.com/image.jpg');
        $parentCategory = new Category(
            'Parent',
            null,
            null,
            'parent',
            '/parent',
            true,
            false,
            false,
            1,
            null,
            null,
            null,
            false,
        );
        $parents = [$parentCategory];

        // Act
        $category = new Category(
            title: 'Test Category',
            description: 'A test description.',
            description2: 'A second test description.',
            slug: 'test-category',
            url: '/test-category',
            active: true,
            featured: true,
            tradeOnly: false,
            sortOrder: 10,
            metaTitle: 'Meta Title',
            metaDescription: 'Meta Description',
            metaKeywords: 'keyword1, keyword2',
            metaNoIndex: true,
            image: $image,
            parents: $parents,
        );

        // Assert
        self::assertSame('Test Category', $category->title);
        self::assertSame('A test description.', $category->description);
        self::assertSame('A second test description.', $category->description2);
        self::assertSame('test-category', $category->slug);
        self::assertSame('/test-category', $category->url);
        self::assertTrue($category->active);
        self::assertTrue($category->featured);
        self::assertFalse($category->tradeOnly);
        self::assertSame(10, $category->sortOrder);
        self::assertSame('Meta Title', $category->metaTitle);
        self::assertSame('Meta Description', $category->metaDescription);
        self::assertSame('keyword1, keyword2', $category->metaKeywords);
        self::assertTrue($category->metaNoIndex);
        self::assertSame($image, $category->image);
        self::assertSame($parents, $category->parents);
    }

    #[Test]
    public function it_constructs_with_minimal_properties_and_correct_defaults(): void
    {
        // Arrange & Act
        $category = new Category(
            title: 'Minimal Category',
            description: 'Desc',
            description2: null,
            slug: 'minimal-category',
            url: '/minimal',
            active: true,
            featured: false,
            tradeOnly: true,
            sortOrder: 5,
            metaTitle: null,
            metaDescription: null,
            metaKeywords: null,
            metaNoIndex: false,
        );

        // Assert
        self::assertNull($category->image, 'Image should default to null.');
        self::assertSame([], $category->parents, 'Parents should default to an empty array.');
    }

    #[Test]
    public function it_correctly_handles_null_for_all_nullable_properties(): void
    {
        // Arrange & Act
        $category = new Category(
            title: 'Nullable Category',
            description: null,
            description2: null,
            slug: 'nullable-category',
            url: '/nullable',
            active: false,
            featured: false,
            tradeOnly: false,
            sortOrder: 0,
            metaTitle: null,
            metaDescription: null,
            metaKeywords: null,
            metaNoIndex: false,
            image: null,
        );

        // Assert
        self::assertNull($category->description);
        self::assertNull($category->description2);
        self::assertNull($category->metaTitle);
        self::assertNull($category->metaDescription);
        self::assertNull($category->metaKeywords);
        self::assertNull($category->image);
    }

    #[Test]
    public function it_preserves_parent_category_hierarchy_and_order(): void
    {
        // Arrange: Create a grandparent -> parent hierarchy
        $grandparent = new Category('Grandparent', null, null, 'gp', '/gp', true, false, false, 1, null, null, null, false);
        $parent = new Category('Parent', null, null, 'p', '/p', true, false, false, 2, null, null, null, false, null, [$grandparent]);
        $parentsList = [$parent, $grandparent]; // Closest first, root last

        // Act
        $category = new Category(
            title: 'Child Category',
            description: null,
            description2: null,
            slug: 'child',
            url: '/child',
            active: true,
            featured: false,
            tradeOnly: false,
            sortOrder: 3,
            metaTitle: null,
            metaDescription: null,
            metaKeywords: null,
            metaNoIndex: false,
            image: null,
            parents: $parentsList,
        );

        // Assert
        self::assertCount(2, $category->parents);
        self::assertSame($parentsList, $category->parents, 'The parents array reference and order should be preserved.');
        self::assertSame($parent, $category->parents[0]);
        self::assertSame($grandparent, $category->parents[1]);
    }
}
