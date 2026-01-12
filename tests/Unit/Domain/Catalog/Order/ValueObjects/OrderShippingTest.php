<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Order\ValueObjects;

use App\Domain\Catalog\Order\ValueObjects\OrderShipping;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * OrderShipping Value Object Unit Tests.
 *
 * Tests the OrderShipping domain value object including assertions.
 */
#[CoversClass(OrderShipping::class)]
final class OrderShippingTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Test Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * Create a valid order shipping with optional overrides.
     *
     * @param array<string, mixed> $overrides
     */
    private function createOrderShipping(array $overrides = []): OrderShipping
    {
        $defaults = [
            'id' => 42,
            'name' => 'Standard Delivery',
            'value' => 5.99,
            'vatRate' => 20.0,
        ];

        $data = \array_merge($defaults, $overrides);

        return new OrderShipping(...$data);
    }

    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_order_shipping_with_valid_data(): void
    {
        $shipping = $this->createOrderShipping();

        $this->assertSame('Standard Delivery', $shipping->name);
        $this->assertSame(5.99, $shipping->value);
        $this->assertSame(20.0, $shipping->vatRate);
    }

    /*
    |--------------------------------------------------------------------------
    | Value Assertion Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_if_value_is_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Shipping value cannot be negative');

        $this->createOrderShipping(['value' => -0.01]);
    }

    #[Test]
    public function it_accepts_zero_value(): void
    {
        $shipping = $this->createOrderShipping(['value' => 0.0]);

        $this->assertSame(0.0, $shipping->value);
    }

    #[Test]
    public function it_accepts_positive_value(): void
    {
        $shipping = $this->createOrderShipping(['value' => 15.99]);

        $this->assertSame(15.99, $shipping->value);
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

        $this->createOrderShipping(['vatRate' => -0.01]);
    }

    #[Test]
    public function it_accepts_zero_vat_rate(): void
    {
        $shipping = $this->createOrderShipping(['vatRate' => 0.0]);

        $this->assertSame(0.0, $shipping->vatRate);
    }

    #[Test]
    public function it_accepts_positive_vat_rate(): void
    {
        $shipping = $this->createOrderShipping(['vatRate' => 20.0]);

        $this->assertSame(20.0, $shipping->vatRate);
    }
}
