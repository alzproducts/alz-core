<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\ValueObjects\VariationListItem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(VariationListItem::class)]
final class VariationListItemTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | resolveImage() — Null / Empty Handling
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_null_when_image_index_is_null(): void
    {
        $result = VariationListItem::resolveImage(null, self::createImages(3));

        self::assertNull($result);
    }

    #[Test]
    public function it_returns_null_when_parent_images_are_empty(): void
    {
        $result = VariationListItem::resolveImage(1, []);

        self::assertNull($result);
    }

    /*
    |--------------------------------------------------------------------------
    | resolveImage() — 1-Based Index Conversion
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_resolves_first_image_for_index_one(): void
    {
        $result = VariationListItem::resolveImage(1, self::createImages(3));

        self::assertNotNull($result);
        self::assertSame('https://example.com/image-1.jpg', $result->url);
    }

    #[Test]
    public function it_resolves_second_image_for_index_two(): void
    {
        $result = VariationListItem::resolveImage(2, self::createImages(3));

        self::assertNotNull($result);
        self::assertSame('https://example.com/image-2.jpg', $result->url);
    }

    #[Test]
    public function it_resolves_last_image_for_index_equal_to_array_length(): void
    {
        $result = VariationListItem::resolveImage(3, self::createImages(3));

        self::assertNotNull($result);
        self::assertSame('https://example.com/image-3.jpg', $result->url);
    }

    #[Test]
    public function it_hydrates_product_image_from_raw_array_keys(): void
    {
        $images = [
            ['id' => 42, 'url' => 'https://cdn.example.com/front.jpg', 'description' => 'Front view', 'sort_order' => 7],
        ];

        $result = VariationListItem::resolveImage(1, $images);

        self::assertNotNull($result);
        self::assertSame('https://cdn.example.com/front.jpg', $result->url);
        self::assertSame('Front view', $result->description);
    }

    /*
    |--------------------------------------------------------------------------
    | resolveImage() — Out-of-Bounds Protection
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_null_when_index_is_zero(): void
    {
        $result = VariationListItem::resolveImage(0, self::createImages(3));

        self::assertNull($result);
    }

    #[Test]
    public function it_returns_null_when_index_is_negative(): void
    {
        $result = VariationListItem::resolveImage(-1, self::createImages(3));

        self::assertNull($result);
    }

    #[Test]
    public function it_returns_null_when_index_exceeds_array_length(): void
    {
        $result = VariationListItem::resolveImage(5, self::createImages(3));

        self::assertNull($result);
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * @return list<array{id: int, url: string, description: string|null, sort_order: int}>
     */
    private static function createImages(int $count): array
    {
        $images = [];
        for ($i = 1; $i <= $count; $i++) {
            $images[] = [
                'id' => $i,
                'url' => "https://example.com/image-{$i}.jpg",
                'description' => null,
                'sort_order' => $i,
            ];
        }

        return $images;
    }
}
