<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\ValueObjects;

use App\Domain\Catalog\ValueObjects\Category;
use App\Domain\Catalog\ValueObjects\CategoryImage;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webmozart\Assert\InvalidArgumentException;

#[CoversClass(Category::class)]
final class CategoryTest extends TestCase
{
    #[Test]
    public function it_constructs_with_all_properties_on_happy_path(): void
    {
        // Arrange
        $image = new CategoryImage('https://example.com/image.jpg');
        $createdAt = new DateTimeImmutable('2026-01-15T10:30:00Z');
        $parentIds = [10, 5];

        // Act
        $category = new Category(
            id: 42,
            createdAt: $createdAt,
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
            parentIds: $parentIds,
        );

        // Assert
        self::assertSame(42, $category->id);
        self::assertSame($createdAt, $category->createdAt);
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
        self::assertSame($parentIds, $category->parentIds);
    }

    #[Test]
    public function it_constructs_with_minimal_properties_and_correct_defaults(): void
    {
        // Arrange & Act
        $category = new Category(
            id: 1,
            createdAt: new DateTimeImmutable('2026-01-01'),
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
        self::assertSame([], $category->parentIds, 'Parent IDs should default to an empty array.');
        self::assertSame([], $category->customFields, 'Custom fields should default to an empty array.');
    }

    #[Test]
    public function it_correctly_handles_null_for_all_nullable_properties(): void
    {
        // Arrange & Act
        $category = new Category(
            id: 99,
            createdAt: new DateTimeImmutable('2026-02-01'),
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
    public function it_preserves_parent_id_order(): void
    {
        // Arrange: Parent IDs closest first, root last
        $parentIds = [30, 20, 10];

        // Act
        $category = new Category(
            id: 40,
            createdAt: new DateTimeImmutable('2026-03-01'),
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
            parentIds: $parentIds,
        );

        // Assert
        self::assertCount(3, $category->parentIds);
        self::assertSame($parentIds, $category->parentIds, 'The parent IDs array and order should be preserved.');
        self::assertSame(30, $category->parentIds[0]);
        self::assertSame(10, $category->parentIds[2]);
    }

    #[Test]
    public function it_rejects_zero_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Category(
            id: 0,
            createdAt: new DateTimeImmutable(),
            title: 'Bad',
            description: null,
            description2: null,
            slug: 'bad',
            url: '/bad',
            active: true,
            featured: false,
            tradeOnly: false,
            sortOrder: 0,
            metaTitle: null,
            metaDescription: null,
            metaKeywords: null,
            metaNoIndex: false,
        );
    }

    #[Test]
    public function it_rejects_negative_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Category(
            id: -1,
            createdAt: new DateTimeImmutable(),
            title: 'Bad',
            description: null,
            description2: null,
            slug: 'bad',
            url: '/bad',
            active: true,
            featured: false,
            tradeOnly: false,
            sortOrder: 0,
            metaTitle: null,
            metaDescription: null,
            metaKeywords: null,
            metaNoIndex: false,
        );
    }
}
