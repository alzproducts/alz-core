<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Mappers;

use App\Domain\Catalog\Order\ValueObjects\OrderLifecycleStatus;
use App\Domain\Catalog\Order\ValueObjects\OrderStatusType;

/**
 * Maps ShopWired OrderStatusType to domain OrderLifecycleStatus.
 *
 * Used when reading orders from the database to derive the high-level
 * lifecycle status from ShopWired's granular status types.
 *
 * Many-to-one mapping: multiple ShopWired statuses map to fewer lifecycle states.
 */
final class StatusTypeToLifecycleMapper
{
    /**
     * Convert a ShopWired status type to domain lifecycle status.
     *
     * Mapping logic:
     * - Pre-fulfillment statuses (NotPaid, Paid, Processing, etc.) → Processing
     * - Dispatched/Completed → Dispatched
     * - Cancelled → Cancelled
     * - PartRefunded → PartRefunded
     * - Refunded → Refunded
     */
    public static function toLifecycle(OrderStatusType $statusType): OrderLifecycleStatus
    {
        return match ($statusType) {
            // Terminal states - explicit mappings
            OrderStatusType::Cancelled => OrderLifecycleStatus::Cancelled,
            OrderStatusType::Refunded => OrderLifecycleStatus::Refunded,
            OrderStatusType::PartRefunded => OrderLifecycleStatus::PartRefunded,

            // Fulfilled states
            OrderStatusType::Dispatched,
            OrderStatusType::Completed => OrderLifecycleStatus::Dispatched,

            // All pre-fulfillment states default to Processing
            OrderStatusType::NotPaid,
            OrderStatusType::PartPaid,
            OrderStatusType::Paid,
            OrderStatusType::AwaitingPayment,
            OrderStatusType::Outstanding,
            OrderStatusType::Preorder,
            OrderStatusType::Overdue,
            OrderStatusType::Processing,
            OrderStatusType::Received => OrderLifecycleStatus::Processing,
        };
    }
}
