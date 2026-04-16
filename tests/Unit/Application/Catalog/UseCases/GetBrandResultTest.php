<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\UseCases;

use App\Application\Catalog\UseCases\GetBrandResult;
use App\Domain\Catalog\Brand\Enums\BrandInclude;
use App\Domain\Catalog\Brand\ValueObjects\BrandLinks;
use App\Domain\Catalog\Brand\ValueObjects\BrandView;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetBrandResult::class)]
final class GetBrandResultTest extends TestCase
{
    #[Test]
    public function constructor_holds_brand_and_includes_correctly(): void
    {
        // Arrange
        $brand = self::buildBrandView();
        $includes = [BrandInclude::CustomFields, BrandInclude::Description];

        // Act
        $result = new GetBrandResult(brand: $brand, includes: $includes);

        // Assert
        self::assertSame($brand, $result->brand);
        self::assertSame($includes, $result->includes);
    }

    #[Test]
    public function has_include_returns_true_when_include_is_present(): void
    {
        // Arrange
        $result = new GetBrandResult(
            brand: self::buildBrandView(),
            includes: [BrandInclude::CustomFields, BrandInclude::Description],
        );

        // Act & Assert
        self::assertTrue($result->hasInclude(BrandInclude::CustomFields));
        self::assertTrue($result->hasInclude(BrandInclude::Description));
    }

    #[Test]
    public function has_include_returns_false_when_include_is_absent(): void
    {
        // Arrange
        $result = new GetBrandResult(
            brand: self::buildBrandView(),
            includes: [BrandInclude::CustomFields],
        );

        // Act & Assert
        self::assertFalse($result->hasInclude(BrandInclude::Description));
    }

    #[Test]
    public function has_include_returns_false_when_includes_list_is_empty(): void
    {
        // Arrange
        $result = new GetBrandResult(
            brand: self::buildBrandView(),
            includes: [],
        );

        // Act & Assert
        self::assertFalse($result->hasInclude(BrandInclude::CustomFields));
    }

    private static function buildBrandView(): BrandView
    {
        return new BrandView(
            id: IntId::from(1),
            title: 'Test Brand',
            slug: 'test-brand',
            links: new BrandLinks(
                publicUrl: '/brands/test-brand',
                editWebsiteUrl: 'https://admin.myshopwired.uk/business/manage-ecommerce-add-brand/1',
            ),
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
