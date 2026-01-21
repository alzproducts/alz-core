<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\ValueObjects\ProductImage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductImage::class)]
final class ProductImageTest extends TestCase
{
    // ========================================================================
    // Happy Path
    // ========================================================================

    #[Test]
    public function it_creates_valid_image(): void
    {
        $image = new ProductImage(
            id: 12345,
            url: 'https://example.com/image.jpg',
            description: 'Product front view',
            sortOrder: 0,
        );

        self::assertSame(12345, $image->id);
        self::assertSame('https://example.com/image.jpg', $image->url);
        self::assertSame('Product front view', $image->description);
        self::assertSame(0, $image->sortOrder);
    }

    #[Test]
    public function it_allows_null_description(): void
    {
        $image = new ProductImage(
            id: 1,
            url: 'https://example.com/image.jpg',
            description: null,
            sortOrder: 0,
        );

        self::assertNull($image->description);
    }

    // ========================================================================
    // Primary Image Detection
    // ========================================================================

    #[Test]
    public function is_primary_returns_true_for_sort_order_zero(): void
    {
        $image = new ProductImage(
            id: 1,
            url: 'https://example.com/image.jpg',
            description: null,
            sortOrder: 0,
        );

        self::assertTrue($image->isPrimary());
    }

    #[Test]
    public function is_primary_returns_false_for_non_zero_sort_order(): void
    {
        $image = new ProductImage(
            id: 1,
            url: 'https://example.com/image.jpg',
            description: null,
            sortOrder: 1,
        );

        self::assertFalse($image->isPrimary());
    }

    // ========================================================================
    // Validation
    // ========================================================================

    #[Test]
    public function it_rejects_non_positive_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Image ID must be positive');

        new ProductImage(
            id: 0,
            url: 'https://example.com/image.jpg',
            description: null,
            sortOrder: 0,
        );
    }

    #[Test]
    public function it_rejects_empty_url(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Image URL cannot be empty');

        new ProductImage(
            id: 1,
            url: '',
            description: null,
            sortOrder: 0,
        );
    }

    #[Test]
    public function it_rejects_negative_sort_order(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sort order cannot be negative');

        new ProductImage(
            id: 1,
            url: 'https://example.com/image.jpg',
            description: null,
            sortOrder: -1,
        );
    }

    // ========================================================================
    // Serialization
    // ========================================================================

    #[Test]
    public function to_array_returns_correct_structure(): void
    {
        $image = new ProductImage(
            id: 12345,
            url: 'https://example.com/image.jpg',
            description: 'Alt text',
            sortOrder: 2,
        );

        $array = $image->toArray();

        self::assertSame([
            'id' => 12345,
            'url' => 'https://example.com/image.jpg',
            'description' => 'Alt text',
            'sort_order' => 2,
        ], $array);
    }

    #[Test]
    public function from_array_creates_valid_image(): void
    {
        $data = [
            'id' => 12345,
            'url' => 'https://example.com/image.jpg',
            'description' => 'Alt text',
            'sort_order' => 2,
        ];

        $image = ProductImage::fromArray($data);

        self::assertSame(12345, $image->id);
        self::assertSame('https://example.com/image.jpg', $image->url);
        self::assertSame('Alt text', $image->description);
        self::assertSame(2, $image->sortOrder);
    }

    #[Test]
    public function round_trip_preserves_data(): void
    {
        $original = new ProductImage(
            id: 99,
            url: 'https://cdn.example.com/products/99.png',
            description: null,
            sortOrder: 5,
        );

        $restored = ProductImage::fromArray($original->toArray());

        self::assertSame($original->id, $restored->id);
        self::assertSame($original->url, $restored->url);
        self::assertSame($original->description, $restored->description);
        self::assertSame($original->sortOrder, $restored->sortOrder);
    }
}
