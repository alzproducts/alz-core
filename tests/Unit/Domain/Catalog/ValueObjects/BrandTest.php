<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\ValueObjects;

use App\Domain\Catalog\ValueObjects\Brand;
use App\Domain\Catalog\ValueObjects\BrandImage;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webmozart\Assert\InvalidArgumentException;

#[CoversClass(Brand::class)]
final class BrandTest extends TestCase
{
    #[Test]
    public function it_constructs_with_all_properties(): void
    {
        $image = new BrandImage('https://example.com/brand.jpg');
        $createdAt = new DateTimeImmutable('2026-01-15T10:30:00Z');
        $customFields = ['colour' => 'Blue'];

        $brand = new Brand(
            id: 7,
            createdAt: $createdAt,
            title: 'Acme Corp',
            description: 'A leading brand.',
            slug: 'acme-corp',
            url: '/brands/acme-corp',
            active: true,
            featured: true,
            sortOrder: 5,
            metaTitle: 'Acme Corp | Shop',
            metaKeywords: 'acme, quality',
            metaDescription: 'Shop Acme Corp products.',
            image: $image,
            customFields: $customFields,
        );

        self::assertSame(7, $brand->id);
        self::assertSame($createdAt, $brand->createdAt);
        self::assertSame('Acme Corp', $brand->title);
        self::assertSame('A leading brand.', $brand->description);
        self::assertSame('acme-corp', $brand->slug);
        self::assertSame('/brands/acme-corp', $brand->url);
        self::assertTrue($brand->active);
        self::assertTrue($brand->featured);
        self::assertSame(5, $brand->sortOrder);
        self::assertSame('Acme Corp | Shop', $brand->metaTitle);
        self::assertSame('acme, quality', $brand->metaKeywords);
        self::assertSame('Shop Acme Corp products.', $brand->metaDescription);
        self::assertSame($image, $brand->image);
        self::assertSame($customFields, $brand->customFields);
    }

    #[Test]
    public function it_constructs_with_defaults(): void
    {
        $brand = new Brand(
            id: 1,
            createdAt: new DateTimeImmutable(),
            title: 'Minimal',
            description: null,
            slug: 'minimal',
            url: '/minimal',
            active: true,
            featured: false,
            sortOrder: 0,
            metaTitle: null,
            metaKeywords: null,
            metaDescription: null,
        );

        self::assertNull($brand->image);
        self::assertSame([], $brand->customFields);
    }

    #[Test]
    public function it_rejects_zero_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Brand(
            id: 0,
            createdAt: new DateTimeImmutable(),
            title: 'Bad',
            description: null,
            slug: 'bad',
            url: '/bad',
            active: true,
            featured: false,
            sortOrder: 0,
            metaTitle: null,
            metaKeywords: null,
            metaDescription: null,
        );
    }

    #[Test]
    public function it_rejects_negative_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Brand(
            id: -1,
            createdAt: new DateTimeImmutable(),
            title: 'Bad',
            description: null,
            slug: 'bad',
            url: '/bad',
            active: true,
            featured: false,
            sortOrder: 0,
            metaTitle: null,
            metaKeywords: null,
            metaDescription: null,
        );
    }
}
