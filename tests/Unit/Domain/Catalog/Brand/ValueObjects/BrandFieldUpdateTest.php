<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Brand\ValueObjects;

use App\Domain\Catalog\Brand\Enums\BrandUpdatableField;
use App\Domain\Catalog\Brand\ValueObjects\BrandFieldUpdate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BrandFieldUpdate::class)]
final class BrandFieldUpdateTest extends TestCase
{
    #[Test]
    public function title_factory_creates_correct_update(): void
    {
        $update = BrandFieldUpdate::title('Acme');

        self::assertSame(BrandUpdatableField::Title, $update->field);
        self::assertSame('Acme', $update->value);
    }

    #[Test]
    public function description_factory_creates_correct_update(): void
    {
        $update = BrandFieldUpdate::description('A great brand');

        self::assertSame(BrandUpdatableField::Description, $update->field);
        self::assertSame('A great brand', $update->value);
    }

    #[Test]
    public function meta_title_factory_creates_correct_update(): void
    {
        $update = BrandFieldUpdate::metaTitle('SEO Title');

        self::assertSame(BrandUpdatableField::MetaTitle, $update->field);
        self::assertSame('SEO Title', $update->value);
    }

    #[Test]
    public function meta_description_factory_creates_correct_update(): void
    {
        $update = BrandFieldUpdate::metaDescription('SEO Description');

        self::assertSame(BrandUpdatableField::MetaDescription, $update->field);
        self::assertSame('SEO Description', $update->value);
    }
}
