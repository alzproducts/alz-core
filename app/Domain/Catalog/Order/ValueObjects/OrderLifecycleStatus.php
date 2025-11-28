<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Order\ValueObjects;

/**
 * High-level order lifecycle states.
 *
 * Represents the business-meaningful stages an order passes through,
 * independent of vendor-specific status systems. This is intentionally
 * simple — complex fulfillment tracking (shipments, tracking numbers)
 * belongs in a dedicated Fulfillment domain.
 *
 * Note: This is separate from OrderStatusType which mirrors the vendor's
 * raw status values for reading order data.
 */
enum OrderLifecycleStatus: string
{
    case Processing = 'processing';
    case Dispatched = 'dispatched';
    case PartDispatched = 'part_dispatched';
    case PartRefunded = 'part_refunded';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';
}
