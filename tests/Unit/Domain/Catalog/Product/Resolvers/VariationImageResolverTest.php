<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\Resolvers;

use App\Domain\Catalog\Product\Resolvers\VariationImageResolver;
use App\Domain\Catalog\Product\ValueObjects\ProductImage;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for VariationImageResolver domain service.
 *
 * Tests the image resolution logic including:
 * - 1-based to 0-based index conversion (ShopWired uses 1-based in UI)
 * - Null/empty handling
 * - Out of bounds protection
 */
#[CoversClass(VariationImageResolver::class)]
final class VariationImageResolverTest extends TestCase
{
    private VariationImageResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new VariationImageResolver();
    }

    /*
    |--------------------------------------------------------------------------
    | Null/Empty Handling
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_null_when_variation_has_no_image_index(): void
    {
        $variation = self::createVariation(imageIndex: null);
        $parentImages = self::createImages(3);

        $result = $this->resolver->resolve($variation, $parentImages);

        self::assertNull($result);
    }

    #[Test]
    public function it_returns_null_when_parent_has_no_images(): void
    {
        $variation = self::createVariation(imageIndex: 1);
        $parentImages = [];

        $result = $this->resolver->resolve($variation, $parentImages);

        self::assertNull($result);
    }

    /*
    |--------------------------------------------------------------------------
    | 1-Based to 0-Based Index Conversion
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_converts_one_based_index_to_zero_based_for_first_image(): void
    {
        $variation = self::createVariation(imageIndex: 1); // 1-based
        $parentImages = self::createImages(3);

        $result = $this->resolver->resolve($variation, $parentImages);

        self::assertNotNull($result);
        self::assertSame('https://example.com/image-0.jpg', $result->url);
    }

    #[Test]
    public function it_converts_one_based_index_to_zero_based_for_second_image(): void
    {
        $variation = self::createVariation(imageIndex: 2); // 1-based
        $parentImages = self::createImages(3);

        $result = $this->resolver->resolve($variation, $parentImages);

        self::assertNotNull($result);
        self::assertSame('https://example.com/image-1.jpg', $result->url);
    }

    #[Test]
    public function it_converts_one_based_index_to_zero_based_for_last_image(): void
    {
        $variation = self::createVariation(imageIndex: 3); // 1-based, last of 3
        $parentImages = self::createImages(3);

        $result = $this->resolver->resolve($variation, $parentImages);

        self::assertNotNull($result);
        self::assertSame('https://example.com/image-2.jpg', $result->url);
    }

    /*
    |--------------------------------------------------------------------------
    | Out of Bounds Protection
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_null_when_index_is_zero(): void
    {
        // Index 0 would convert to -1 (out of bounds)
        $variation = self::createVariation(imageIndex: 0);
        $parentImages = self::createImages(3);

        $result = $this->resolver->resolve($variation, $parentImages);

        self::assertNull($result);
    }

    #[Test]
    public function it_returns_null_when_index_exceeds_array_bounds(): void
    {
        // Index 5 would convert to 4, but only 3 images exist (0, 1, 2)
        $variation = self::createVariation(imageIndex: 5);
        $parentImages = self::createImages(3);

        $result = $this->resolver->resolve($variation, $parentImages);

        self::assertNull($result);
    }

    #[Test]
    public function it_returns_null_when_index_equals_array_length_plus_one(): void
    {
        // Edge case: 1-based index 4 for array of 3 images
        $variation = self::createVariation(imageIndex: 4);
        $parentImages = self::createImages(3);

        $result = $this->resolver->resolve($variation, $parentImages);

        self::assertNull($result);
    }

    /*
    |--------------------------------------------------------------------------
    | resolveUrl() Convenience Method
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function resolve_url_returns_url_string_directly(): void
    {
        $variation = self::createVariation(imageIndex: 2);
        $parentImages = self::createImages(3);

        $result = $this->resolver->resolveUrl($variation, $parentImages);

        self::assertSame('https://example.com/image-1.jpg', $result);
    }

    #[Test]
    public function resolve_url_returns_null_when_no_image(): void
    {
        $variation = self::createVariation(imageIndex: null);
        $parentImages = self::createImages(3);

        $result = $this->resolver->resolveUrl($variation, $parentImages);

        self::assertNull($result);
    }

    /*
    |--------------------------------------------------------------------------
    | Edge Cases
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_handles_single_image_array(): void
    {
        $variation = self::createVariation(imageIndex: 1);
        $parentImages = self::createImages(1);

        $result = $this->resolver->resolve($variation, $parentImages);

        self::assertNotNull($result);
        self::assertSame('https://example.com/image-0.jpg', $result->url);
    }

    #[Test]
    public function it_returns_null_for_negative_image_index(): void
    {
        // Defensive: if somehow a negative index got through
        $variation = self::createVariation(imageIndex: -1);
        $parentImages = self::createImages(3);

        $result = $this->resolver->resolve($variation, $parentImages);

        self::assertNull($result);
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    private static function createVariation(?int $imageIndex): ProductVariation
    {
        return new ProductVariation(
            id: 1001,
            productExternalId: 12345,
            sku: 'VAR-001',
            price: 29.99,
            costPrice: 15.00,
            salePrice: null,
            stock: 10,
            weight: null,
            gtin: null,
            mpn: null,
            imageIndex: $imageIndex,
            options: [],
        );
    }

    /**
     * @return list<ProductImage>
     */
    private static function createImages(int $count): array
    {
        $images = [];
        for ($i = 0; $i < $count; $i++) {
            $images[] = new ProductImage(
                id: $i + 1,
                url: "https://example.com/image-{$i}.jpg",
                description: null,
                sortOrder: $i,
            );
        }

        return $images;
    }
}
