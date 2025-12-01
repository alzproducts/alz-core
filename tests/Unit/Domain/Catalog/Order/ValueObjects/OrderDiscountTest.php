<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Order\ValueObjects;

use App\Domain\Catalog\Order\ValueObjects\OrderDiscount;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * OrderDiscount Value Object Unit Tests.
 *
 * Tests the OrderDiscount domain value object including assertions.
 */
#[CoversClass(OrderDiscount::class)]
final class OrderDiscountTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Test Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * Create a valid order discount with optional overrides.
     *
     * @param array<string, mixed> $overrides
     */
    private function createOrderDiscount(array $overrides = []): OrderDiscount
    {
        $defaults = [
            'name' => 'Summer Sale',
            'value' => 10.00,
            'type' => 'percentage',
            'code' => 'SUMMER10',
            'voucherId' => 42,
            'offerId' => 7,
        ];

        $data = \array_merge($defaults, $overrides);

        return new OrderDiscount(...$data);
    }

    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_order_discount_with_all_fields(): void
    {
        $discount = $this->createOrderDiscount();

        $this->assertSame('Summer Sale', $discount->name);
        $this->assertSame(10.00, $discount->value);
        $this->assertSame('percentage', $discount->type);
        $this->assertSame('SUMMER10', $discount->code);
        $this->assertSame(42, $discount->voucherId);
        $this->assertSame(7, $discount->offerId);
    }

    #[Test]
    public function it_creates_order_discount_with_nullable_fields_as_null(): void
    {
        $discount = $this->createOrderDiscount([
            'type' => null,
            'code' => null,
            'voucherId' => null,
            'offerId' => null,
        ]);

        $this->assertNull($discount->type);
        $this->assertNull($discount->code);
        $this->assertNull($discount->voucherId);
        $this->assertNull($discount->offerId);
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
        $this->expectExceptionMessage('Discount value cannot be negative');

        $this->createOrderDiscount(['value' => -0.01]);
    }

    #[Test]
    public function it_accepts_zero_value(): void
    {
        $discount = $this->createOrderDiscount(['value' => 0.0]);

        $this->assertSame(0.0, $discount->value);
    }

    #[Test]
    public function it_accepts_positive_value(): void
    {
        $discount = $this->createOrderDiscount(['value' => 99.99]);

        $this->assertSame(99.99, $discount->value);
    }
}
