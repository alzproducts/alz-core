<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Order\ValueObjects;

/**
 * Order status types from ShopWired.
 *
 * Complete list of possible order statuses. Use ::from() for strict
 * parsing - unknown values will throw ValueError, signaling API changes.
 */
enum OrderStatusType: string
{
    case NotPaid = 'Not Paid';
    case PartPaid = 'Part Paid';
    case Paid = 'Paid';
    case Cancelled = 'Cancelled';
    case Dispatched = 'Dispatched';
    case Completed = 'Completed';
    case PartRefunded = 'Part Refunded';
    case Refunded = 'Refunded';
    case AwaitingPayment = 'Awaiting Payment';
    case Outstanding = 'Outstanding';
    case Preorder = 'Preorder';
    case Overdue = 'Overdue';
    case Processing = 'Processing';
    case Received = 'Received';
}
