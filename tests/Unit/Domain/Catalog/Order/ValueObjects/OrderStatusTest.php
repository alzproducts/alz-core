<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Order\ValueObjects;

use App\Domain\Catalog\Order\ValueObjects\OrderStatus;
use App\Domain\Catalog\Order\ValueObjects\OrderStatusType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * OrderStatus Value Object Unit Tests.
 *
 * Tests the Domain value object that wraps OrderStatusType enum with the raw API type string.
 */
#[CoversClass(OrderStatus::class)]
final class OrderStatusTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_an_order_status_with_valid_data(): void
    {
        $status = new OrderStatus(
            name: OrderStatusType::Completed,
            type: 'shipped',
        );

        $this->assertSame(OrderStatusType::Completed, $status->name);
        $this->assertSame('shipped', $status->type);
    }

    #[Test]
    #[DataProvider('statusTypeProvider')]
    public function it_accepts_all_order_status_types(OrderStatusType $statusType): void
    {
        $status = new OrderStatus(
            name: $statusType,
            type: 'custom',
        );

        $this->assertSame($statusType, $status->name);
    }

    /**
     * @return array<string, array{OrderStatusType}>
     */
    public static function statusTypeProvider(): array
    {
        return [
            'NotPaid' => [OrderStatusType::NotPaid],
            'PartPaid' => [OrderStatusType::PartPaid],
            'Paid' => [OrderStatusType::Paid],
            'Cancelled' => [OrderStatusType::Cancelled],
            'Dispatched' => [OrderStatusType::Dispatched],
            'Completed' => [OrderStatusType::Completed],
            'PartRefunded' => [OrderStatusType::PartRefunded],
            'Refunded' => [OrderStatusType::Refunded],
            'AwaitingPayment' => [OrderStatusType::AwaitingPayment],
            'Outstanding' => [OrderStatusType::Outstanding],
            'Preorder' => [OrderStatusType::Preorder],
            'Overdue' => [OrderStatusType::Overdue],
            'Processing' => [OrderStatusType::Processing],
            'Received' => [OrderStatusType::Received],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Type Field Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('typeValueProvider')]
    public function it_accepts_various_type_values(string $type): void
    {
        $status = new OrderStatus(
            name: OrderStatusType::Paid,
            type: $type,
        );

        $this->assertSame($type, $status->type);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function typeValueProvider(): array
    {
        return [
            'paid type' => ['paid'],
            'unpaid type' => ['unpaid'],
            'cancelled type' => ['cancelled'],
            'shipped type' => ['shipped'],
            'custom type' => ['custom'],
            'empty type' => [''],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Common Status Combinations
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_represents_a_paid_order(): void
    {
        $status = new OrderStatus(
            name: OrderStatusType::Paid,
            type: 'paid',
        );

        $this->assertSame(OrderStatusType::Paid, $status->name);
        $this->assertSame('paid', $status->type);
    }

    #[Test]
    public function it_represents_an_unpaid_order(): void
    {
        $status = new OrderStatus(
            name: OrderStatusType::NotPaid,
            type: 'unpaid',
        );

        $this->assertSame(OrderStatusType::NotPaid, $status->name);
        $this->assertSame('unpaid', $status->type);
    }

    #[Test]
    public function it_represents_a_cancelled_order(): void
    {
        $status = new OrderStatus(
            name: OrderStatusType::Cancelled,
            type: 'cancelled',
        );

        $this->assertSame(OrderStatusType::Cancelled, $status->name);
        $this->assertSame('cancelled', $status->type);
    }

    #[Test]
    public function it_represents_a_dispatched_order(): void
    {
        $status = new OrderStatus(
            name: OrderStatusType::Dispatched,
            type: 'shipped',
        );

        $this->assertSame(OrderStatusType::Dispatched, $status->name);
        $this->assertSame('shipped', $status->type);
    }
}
