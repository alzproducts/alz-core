<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired\Mappers;

use App\Domain\Catalog\Order\ValueObjects\OrderLifecycleStatus;
use App\Domain\Catalog\Order\ValueObjects\OrderStatusType;
use App\Infrastructure\Shopwired\Mappers\StatusTypeToLifecycleMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * StatusTypeToLifecycleMapper Unit Tests.
 *
 * Tests the mapping from ShopWired OrderStatusType to domain OrderLifecycleStatus.
 * This is a many-to-one mapping where multiple granular statuses collapse
 * into fewer lifecycle states.
 */
#[CoversClass(StatusTypeToLifecycleMapper::class)]
final class StatusTypeToLifecycleMapperTest extends TestCase
{
    #[Test]
    #[DataProvider('statusMappingProvider')]
    public function it_maps_status_type_to_lifecycle(
        OrderStatusType $statusType,
        OrderLifecycleStatus $expectedLifecycle,
    ): void {
        $actualLifecycle = StatusTypeToLifecycleMapper::toLifecycle($statusType);

        self::assertSame($expectedLifecycle, $actualLifecycle);
    }

    /**
     * @return array<string, array{OrderStatusType, OrderLifecycleStatus}>
     */
    public static function statusMappingProvider(): array
    {
        return [
            // Pre-fulfillment → Processing
            'NotPaid → Processing' => [OrderStatusType::NotPaid, OrderLifecycleStatus::Processing],
            'PartPaid → Processing' => [OrderStatusType::PartPaid, OrderLifecycleStatus::Processing],
            'Paid → Processing' => [OrderStatusType::Paid, OrderLifecycleStatus::Processing],
            'AwaitingPayment → Processing' => [OrderStatusType::AwaitingPayment, OrderLifecycleStatus::Processing],
            'Outstanding → Processing' => [OrderStatusType::Outstanding, OrderLifecycleStatus::Processing],
            'Preorder → Processing' => [OrderStatusType::Preorder, OrderLifecycleStatus::Processing],
            'Overdue → Processing' => [OrderStatusType::Overdue, OrderLifecycleStatus::Processing],
            'Processing → Processing' => [OrderStatusType::Processing, OrderLifecycleStatus::Processing],
            'Received → Processing' => [OrderStatusType::Received, OrderLifecycleStatus::Processing],

            // Fulfilled → Dispatched
            'Dispatched → Dispatched' => [OrderStatusType::Dispatched, OrderLifecycleStatus::Dispatched],
            'Completed → Dispatched' => [OrderStatusType::Completed, OrderLifecycleStatus::Dispatched],

            // Terminal states
            'Cancelled → Cancelled' => [OrderStatusType::Cancelled, OrderLifecycleStatus::Cancelled],
            'PartRefunded → PartRefunded' => [OrderStatusType::PartRefunded, OrderLifecycleStatus::PartRefunded],
            'Refunded → Refunded' => [OrderStatusType::Refunded, OrderLifecycleStatus::Refunded],
        ];
    }

    #[Test]
    public function it_maps_all_status_type_cases(): void
    {
        // Ensure every OrderStatusType case has a mapping (exhaustive match)
        foreach (OrderStatusType::cases() as $statusType) {
            $lifecycle = StatusTypeToLifecycleMapper::toLifecycle($statusType);

            self::assertInstanceOf(OrderLifecycleStatus::class, $lifecycle);
        }
    }

    #[Test]
    public function completed_and_dispatched_both_map_to_dispatched(): void
    {
        // Business rule: "Completed" is ShopWired's term for fully dispatched
        self::assertSame(
            StatusTypeToLifecycleMapper::toLifecycle(OrderStatusType::Dispatched),
            StatusTypeToLifecycleMapper::toLifecycle(OrderStatusType::Completed),
        );
    }
}
