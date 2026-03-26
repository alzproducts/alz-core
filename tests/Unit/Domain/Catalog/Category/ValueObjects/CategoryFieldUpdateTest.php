<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Category\ValueObjects;

use App\Domain\Catalog\Category\Enums\CategoryUpdatableField;
use App\Domain\Catalog\Category\ValueObjects\CategoryFieldUpdate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CategoryFieldUpdate::class)]
final class CategoryFieldUpdateTest extends TestCase
{
    #[Test]
    public function title_factory_creates_correct_update(): void
    {
        $update = CategoryFieldUpdate::title('Electronics');

        self::assertSame(CategoryUpdatableField::Title, $update->field);
        self::assertSame('Electronics', $update->value);
    }

    #[Test]
    public function description_factory_creates_correct_update(): void
    {
        $update = CategoryFieldUpdate::description('All electronic goods');

        self::assertSame(CategoryUpdatableField::Description, $update->field);
        self::assertSame('All electronic goods', $update->value);
    }

    #[Test]
    public function meta_title_factory_creates_correct_update(): void
    {
        $update = CategoryFieldUpdate::metaTitle('SEO Title');

        self::assertSame(CategoryUpdatableField::MetaTitle, $update->field);
        self::assertSame('SEO Title', $update->value);
    }

    #[Test]
    public function meta_description_factory_creates_correct_update(): void
    {
        $update = CategoryFieldUpdate::metaDescription('SEO Description');

        self::assertSame(CategoryUpdatableField::MetaDescription, $update->field);
        self::assertSame('SEO Description', $update->value);
    }
}
