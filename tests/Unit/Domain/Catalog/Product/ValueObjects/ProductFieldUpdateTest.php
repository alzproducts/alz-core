<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\Enums\ProductUpdatableField;
use App\Domain\Catalog\Product\ValueObjects\ProductFieldUpdate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductFieldUpdate::class)]
final class ProductFieldUpdateTest extends TestCase
{
    #[Test]
    public function title_factory_creates_correct_update(): void
    {
        $update = ProductFieldUpdate::title('New Title');

        self::assertSame(ProductUpdatableField::Title, $update->field);
        self::assertSame('New Title', $update->value);
    }

    #[Test]
    public function description_factory_creates_correct_update(): void
    {
        $update = ProductFieldUpdate::description('Product description');

        self::assertSame(ProductUpdatableField::Description, $update->field);
        self::assertSame('Product description', $update->value);
    }

    #[Test]
    public function meta_title_factory_creates_correct_update(): void
    {
        $update = ProductFieldUpdate::metaTitle('SEO Title');

        self::assertSame(ProductUpdatableField::MetaTitle, $update->field);
        self::assertSame('SEO Title', $update->value);
    }

    #[Test]
    public function meta_description_factory_creates_correct_update(): void
    {
        $update = ProductFieldUpdate::metaDescription('SEO description');

        self::assertSame(ProductUpdatableField::MetaDescription, $update->field);
        self::assertSame('SEO description', $update->value);
    }

    #[Test]
    public function categories_factory_creates_correct_update(): void
    {
        $update = ProductFieldUpdate::categories([1, 2, 3]);

        self::assertSame(ProductUpdatableField::Categories, $update->field);
        self::assertSame([1, 2, 3], $update->value);
    }

    #[Test]
    public function categories_factory_accepts_empty_array(): void
    {
        $update = ProductFieldUpdate::categories([]);

        self::assertSame(ProductUpdatableField::Categories, $update->field);
        self::assertSame([], $update->value);
    }
}
