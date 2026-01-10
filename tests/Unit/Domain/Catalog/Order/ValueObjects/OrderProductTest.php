<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Order\ValueObjects;

use App\Domain\Catalog\Order\ValueObjects\OrderProduct;
use App\Domain\Catalog\Order\ValueObjects\ProductVariation;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * OrderProduct Value Object Unit Tests.
 *
 * Tests the OrderProduct domain value object including assertions.
 */
#[CoversClass(OrderProduct::class)]
final class OrderProductTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Test Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * Create a valid order product with optional overrides.
     *
     * @param array<string, mixed> $overrides
     */
    private function createOrderProduct(array $overrides = []): OrderProduct
    {
        $defaults = [
            'id' => 101,
            'title' => 'Test Product',
            'sku' => 'TEST-SKU-001',
            'price' => 25.00,
            'priceVat' => 5.00,
            'total' => 50.00,
            'totalVat' => 10.00,
            'originalPrice' => 25.00,
            'costPrice' => 12.50,
            'quantity' => 2,
            'vatRate' => 20.0,
            'comments' => '',
            'variation' => [],
            'customFields' => [],
        ];

        $data = \array_merge($defaults, $overrides);

        return new OrderProduct(...$data);
    }

    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_order_product_with_valid_data(): void
    {
        $product = $this->createOrderProduct();

        $this->assertSame(101, $product->id);
        $this->assertSame('Test Product', $product->title);
        $this->assertSame('TEST-SKU-001', $product->sku);
        $this->assertSame(25.00, $product->price);
        $this->assertSame(2, $product->quantity);
        $this->assertSame(20.0, $product->vatRate);
    }

    #[Test]
    public function it_creates_order_product_with_variation_and_custom_fields(): void
    {
        $variation = [
            new ProductVariation('Size', 'Large'),
            new ProductVariation('Color', 'Blue'),
        ];
        $customFields = [
            ['name' => 'Engraving', 'value' => 'Happy Birthday'],
        ];

        $product = $this->createOrderProduct([
            'variation' => $variation,
            'customFields' => $customFields,
        ]);

        $this->assertCount(2, $product->variation);
        $this->assertSame('Size', $product->variation[0]->name);
        $this->assertSame('Large', $product->variation[0]->value);
        $this->assertSame('Color', $product->variation[1]->name);
        $this->assertSame('Blue', $product->variation[1]->value);
        $this->assertSame($customFields, $product->customFields);
    }

    /*
    |--------------------------------------------------------------------------
    | ID Assertion Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('invalidIdProvider')]
    public function it_throws_if_id_is_not_positive(int $invalidId): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Product ID must be positive');

        $this->createOrderProduct(['id' => $invalidId]);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function invalidIdProvider(): array
    {
        return [
            'zero id' => [0],
            'negative id' => [-1],
            'large negative id' => [-99999],
        ];
    }

    #[Test]
    public function it_accepts_positive_boundary_id(): void
    {
        $product = $this->createOrderProduct(['id' => 1]);

        $this->assertSame(1, $product->id);
    }

    /*
    |--------------------------------------------------------------------------
    | Quantity Assertion Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('invalidQuantityProvider')]
    public function it_throws_if_quantity_is_not_positive(int $invalidQuantity): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Quantity must be positive');

        $this->createOrderProduct(['quantity' => $invalidQuantity]);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function invalidQuantityProvider(): array
    {
        return [
            'zero quantity' => [0],
            'negative quantity' => [-1],
            'large negative quantity' => [-100],
        ];
    }

    #[Test]
    public function it_accepts_positive_boundary_quantity(): void
    {
        $product = $this->createOrderProduct(['quantity' => 1]);

        $this->assertSame(1, $product->quantity);
    }

    /*
    |--------------------------------------------------------------------------
    | VAT Rate Assertion Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_if_vat_rate_is_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('VAT rate cannot be negative');

        $this->createOrderProduct(['vatRate' => -0.01]);
    }

    #[Test]
    public function it_accepts_zero_vat_rate(): void
    {
        $product = $this->createOrderProduct(['vatRate' => 0.0]);

        $this->assertSame(0.0, $product->vatRate);
    }

    #[Test]
    public function it_accepts_positive_vat_rate(): void
    {
        $product = $this->createOrderProduct(['vatRate' => 20.0]);

        $this->assertSame(20.0, $product->vatRate);
    }
}
