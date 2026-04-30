<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Http\Api\Resources;

use App\Application\Catalog\UseCases\GetBrandResult;
use App\Domain\Catalog\Brand\Enums\BrandInclude;
use App\Domain\Catalog\Brand\ValueObjects\BrandLinks;
use App\Domain\Catalog\Brand\ValueObjects\BrandView;
use App\Domain\ValueObjects\IntId;
use App\Presentation\Http\Api\Resources\BrandDetailResource;
use DateTimeImmutable;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BrandDetailResource::class)]
final class BrandDetailResourceTest extends TestCase
{
    #[Test]
    public function it_includes_description2_when_description_include_is_present(): void
    {
        $brand = self::buildBrandView(description: 'Primary', description2: '<p>Secondary</p>');
        $result = new GetBrandResult(brand: $brand, includes: [BrandInclude::Description]);

        $data = (new BrandDetailResource($result))->toArray(Request::create('/'));

        self::assertArrayHasKey('description2', $data);
        self::assertSame('<p>Secondary</p>', $data['description2']);
    }

    #[Test]
    public function it_includes_null_description2_when_brand_has_no_secondary_description(): void
    {
        $brand = self::buildBrandView(description: 'Primary', description2: null);
        $result = new GetBrandResult(brand: $brand, includes: [BrandInclude::Description]);

        $data = (new BrandDetailResource($result))->toArray(Request::create('/'));

        self::assertArrayHasKey('description2', $data);
        self::assertNull($data['description2']);
    }

    #[Test]
    public function it_excludes_description2_when_description_include_is_absent(): void
    {
        $brand = self::buildBrandView(description2: '<p>Secondary</p>');
        $result = new GetBrandResult(brand: $brand, includes: []);

        $data = (new BrandDetailResource($result))->toArray(Request::create('/'));

        self::assertArrayNotHasKey('description', $data);
        self::assertArrayNotHasKey('description2', $data);
    }

    private static function buildBrandView(
        ?string $description = null,
        ?string $description2 = null,
    ): BrandView {
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
            description: $description,
            description2: $description2,
        );
    }
}
