<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ContactSubmission\ValueObjects;

use App\Domain\ContactSubmission\Enums\ProductSource;
use App\Domain\ContactSubmission\ValueObjects\SelectedProduct;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SelectedProduct Value Object Unit Tests.
 *
 * Tests the product context attached to contact form submissions.
 * SKU validation and serialization are critical for data integrity.
 */
#[CoversClass(SelectedProduct::class)]
final class SelectedProductTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_with_required_sku_only(): void
    {
        $product = new SelectedProduct(sku: 'ABC-123');

        self::assertSame('ABC-123', $product->sku);
        self::assertNull($product->title);
        self::assertNull($product->price);
        self::assertNull($product->url);
        self::assertNull($product->source);
        self::assertNull($product->manualUrl);
        self::assertNull($product->quantity);
    }

    #[Test]
    public function it_creates_with_all_fields(): void
    {
        $product = new SelectedProduct(
            sku: 'PROD-456',
            title: 'Premium Walking Frame',
            price: '£149.99',
            url: 'https://alzproducts.co.uk/products/walking-frame',
            source: ProductSource::RecentlyViewed,
            manualUrl: 'https://manual.example.com/walking-frame.pdf',
            quantity: 2,
        );

        self::assertSame('PROD-456', $product->sku);
        self::assertSame('Premium Walking Frame', $product->title);
        self::assertSame('£149.99', $product->price);
        self::assertSame('https://alzproducts.co.uk/products/walking-frame', $product->url);
        self::assertSame(ProductSource::RecentlyViewed, $product->source);
        self::assertSame('https://manual.example.com/walking-frame.pdf', $product->manualUrl);
        self::assertSame(2, $product->quantity);
    }

    /*
    |--------------------------------------------------------------------------
    | SKU Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_for_empty_sku(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Product SKU is required');

        new SelectedProduct(sku: '');
    }

    #[Test]
    public function it_accepts_whitespace_sku(): void
    {
        // Note: Assert::notEmpty() only checks for empty string, not whitespace
        // Frontend validation handles whitespace; domain accepts for resilience
        $product = new SelectedProduct(sku: '   ');

        self::assertSame('   ', $product->sku);
    }

    /*
    |--------------------------------------------------------------------------
    | Quantity Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_accepts_null_quantity(): void
    {
        $product = new SelectedProduct(sku: 'SKU-123', quantity: null);

        self::assertNull($product->quantity);
    }

    #[Test]
    public function it_accepts_quantity_of_one(): void
    {
        $product = new SelectedProduct(sku: 'SKU-123', quantity: 1);

        self::assertSame(1, $product->quantity);
    }

    #[Test]
    public function it_accepts_quantity_of_999(): void
    {
        $product = new SelectedProduct(sku: 'SKU-123', quantity: 999);

        self::assertSame(999, $product->quantity);
    }

    #[Test]
    public function it_throws_for_quantity_of_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Quantity must be between 1 and 999');

        new SelectedProduct(sku: 'SKU-123', quantity: 0);
    }

    #[Test]
    public function it_throws_for_quantity_exceeding_999(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Quantity must be between 1 and 999');

        new SelectedProduct(sku: 'SKU-123', quantity: 1000);
    }

    #[Test]
    public function it_throws_for_negative_quantity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Quantity must be between 1 and 999');

        new SelectedProduct(sku: 'SKU-123', quantity: -1);
    }

    /*
    |--------------------------------------------------------------------------
    | toArray() Serialization Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function toArray_serializes_all_fields(): void
    {
        $product = new SelectedProduct(
            sku: 'PROD-789',
            title: 'Test Product',
            price: '£99.99',
            url: 'https://example.com/product',
            source: ProductSource::RecentlyOrdered,
            manualUrl: 'https://example.com/manual.pdf',
            quantity: 5,
        );

        $array = $product->toArray();

        self::assertSame('PROD-789', $array['sku']);
        self::assertSame('Test Product', $array['title']);
        self::assertSame('£99.99', $array['price']);
        self::assertSame('https://example.com/product', $array['url']);
        self::assertSame('recently_ordered', $array['source']);
        self::assertSame('https://example.com/manual.pdf', $array['manual_url']);
        self::assertSame(5, $array['quantity']);
    }

    #[Test]
    public function toArray_serializes_null_fields(): void
    {
        $product = new SelectedProduct(sku: 'MIN-SKU');

        $array = $product->toArray();

        self::assertSame('MIN-SKU', $array['sku']);
        self::assertNull($array['title']);
        self::assertNull($array['price']);
        self::assertNull($array['url']);
        self::assertNull($array['source']);
        self::assertNull($array['manual_url']);
        self::assertNull($array['quantity']);
    }

    #[Test]
    public function toArray_converts_enum_to_string_value(): void
    {
        $product = new SelectedProduct(
            sku: 'TEST',
            source: ProductSource::RecentlyViewed,
        );

        $array = $product->toArray();

        self::assertSame('recently_viewed', $array['source']);
        self::assertIsString($array['source']);
    }

    /*
    |--------------------------------------------------------------------------
    | fromArray() Deserialization Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function fromArray_reconstructs_with_all_fields(): void
    {
        $data = [
            'sku' => 'RESTORED-123',
            'title' => 'Restored Product',
            'price' => '£199.99',
            'url' => 'https://example.com/restored',
            'source' => 'recently_viewed',
            'manual_url' => 'https://example.com/restored-manual.pdf',
            'quantity' => 3,
        ];

        $product = SelectedProduct::fromArray($data);

        self::assertSame('RESTORED-123', $product->sku);
        self::assertSame('Restored Product', $product->title);
        self::assertSame('£199.99', $product->price);
        self::assertSame('https://example.com/restored', $product->url);
        self::assertSame(ProductSource::RecentlyViewed, $product->source);
        self::assertSame('https://example.com/restored-manual.pdf', $product->manualUrl);
        self::assertSame(3, $product->quantity);
    }

    #[Test]
    public function fromArray_handles_null_optionals(): void
    {
        $data = [
            'sku' => 'MINIMAL',
            'title' => null,
            'price' => null,
            'url' => null,
            'source' => null,
            'manual_url' => null,
            'quantity' => null,
        ];

        $product = SelectedProduct::fromArray($data);

        self::assertSame('MINIMAL', $product->sku);
        self::assertNull($product->title);
        self::assertNull($product->price);
        self::assertNull($product->url);
        self::assertNull($product->source);
        self::assertNull($product->manualUrl);
        self::assertNull($product->quantity);
    }

    #[Test]
    public function fromArray_handles_missing_optional_keys(): void
    {
        $data = [
            'sku' => 'SPARSE',
        ];

        $product = SelectedProduct::fromArray($data);

        self::assertSame('SPARSE', $product->sku);
        self::assertNull($product->title);
        self::assertNull($product->source);
    }

    #[Test]
    public function fromArray_throws_for_invalid_source_value(): void
    {
        $this->expectException(InvalidEnumValueException::class);

        SelectedProduct::fromArray([
            'sku' => 'TEST',
            'source' => 'invalid_source',
        ]);
    }

    #[Test]
    public function roundtrip_preserves_data(): void
    {
        $original = new SelectedProduct(
            sku: 'ROUNDTRIP',
            title: 'Roundtrip Test',
            price: '£50.00',
            url: 'https://example.com/roundtrip',
            source: ProductSource::RecentlyOrdered,
            manualUrl: 'https://example.com/roundtrip-manual.pdf',
            quantity: 10,
        );

        $restored = SelectedProduct::fromArray($original->toArray());

        self::assertSame($original->sku, $restored->sku);
        self::assertSame($original->title, $restored->title);
        self::assertSame($original->price, $restored->price);
        self::assertSame($original->url, $restored->url);
        self::assertSame($original->source, $restored->source);
        self::assertSame($original->manualUrl, $restored->manualUrl);
        self::assertSame($original->quantity, $restored->quantity);
    }
}
