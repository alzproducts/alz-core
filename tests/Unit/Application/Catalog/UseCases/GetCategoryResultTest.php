<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\UseCases;

use App\Application\Catalog\UseCases\GetCategoryResult;
use App\Domain\Catalog\Category\ValueObjects\CategoryView;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetCategoryResult::class)]
final class GetCategoryResultTest extends TestCase
{
    #[Test]
    public function constructor_holds_category_and_includes_correctly(): void
    {
        // Arrange
        $category = self::buildCategoryView();
        $includes = ['custom_fields', 'description'];

        // Act
        $result = new GetCategoryResult(category: $category, includes: $includes);

        // Assert
        self::assertSame($category, $result->category);
        self::assertSame($includes, $result->includes);
    }

    #[Test]
    public function has_include_returns_true_when_include_is_present(): void
    {
        // Arrange
        $result = new GetCategoryResult(
            category: self::buildCategoryView(),
            includes: ['custom_fields', 'description'],
        );

        // Act & Assert
        self::assertTrue($result->hasInclude('custom_fields'));
        self::assertTrue($result->hasInclude('description'));
    }

    #[Test]
    public function has_include_returns_false_when_include_is_absent(): void
    {
        // Arrange
        $result = new GetCategoryResult(
            category: self::buildCategoryView(),
            includes: ['custom_fields'],
        );

        // Act & Assert
        self::assertFalse($result->hasInclude('description'));
    }

    #[Test]
    public function has_include_returns_false_when_includes_list_is_empty(): void
    {
        // Arrange
        $result = new GetCategoryResult(
            category: self::buildCategoryView(),
            includes: [],
        );

        // Act & Assert
        self::assertFalse($result->hasInclude('custom_fields'));
    }

    private static function buildCategoryView(): CategoryView
    {
        return new CategoryView(
            id: IntId::from(1),
            title: 'Test Category',
            slug: 'test-category',
            url: '/categories/test-category',
            active: true,
            featured: false,
            sortOrder: 1,
            metaTitle: null,
            metaDescription: null,
            image: null,
            createdAt: new DateTimeImmutable('2025-01-01'),
        );
    }
}
