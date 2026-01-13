<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Order\ValueObjects;

use App\Domain\Catalog\Order\ValueObjects\OrderRefund;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * OrderRefund Value Object Unit Tests.
 *
 * Tests the OrderRefund domain value object including assertions.
 */
#[CoversClass(OrderRefund::class)]
final class OrderRefundTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Test Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * Create a valid order refund with optional overrides.
     *
     * @param array<string, mixed> $overrides
     */
    private function createOrderRefund(array $overrides = []): OrderRefund
    {
        $defaults = [
            'externalId' => 42,
            'name' => 'Customer returned item',
            'value' => 25.50,
            'createdAt' => new DateTimeImmutable('2024-03-15T14:30:00+00:00'),
        ];

        $data = \array_merge($defaults, $overrides);

        return new OrderRefund(...$data);
    }

    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_order_refund_with_all_fields(): void
    {
        $createdAt = new DateTimeImmutable('2024-03-20T09:15:00+00:00');

        $refund = new OrderRefund(
            externalId: 123,
            name: 'Partial refund for damaged goods',
            value: 15.99,
            createdAt: $createdAt,
        );

        $this->assertSame(123, $refund->externalId);
        $this->assertSame('Partial refund for damaged goods', $refund->name);
        $this->assertSame(15.99, $refund->value);
        $this->assertSame($createdAt, $refund->createdAt);
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
        $this->expectExceptionMessage('Refund value cannot be negative');

        $this->createOrderRefund(['value' => -0.01]);
    }

    #[Test]
    public function it_accepts_zero_value(): void
    {
        $refund = $this->createOrderRefund(['value' => 0.0]);

        $this->assertSame(0.0, $refund->value);
    }

    #[Test]
    public function it_accepts_positive_value(): void
    {
        $refund = $this->createOrderRefund(['value' => 199.99]);

        $this->assertSame(199.99, $refund->value);
    }

    #[Test]
    public function it_accepts_empty_name(): void
    {
        $refund = $this->createOrderRefund(['name' => '']);

        $this->assertSame('', $refund->name);
    }
}
