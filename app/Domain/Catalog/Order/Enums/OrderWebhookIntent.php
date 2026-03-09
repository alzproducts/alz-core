<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Order\Enums;

/**
 * Generic business intent for order webhook events.
 *
 * Platform-agnostic — does not contain ShopWired-specific topic strings.
 * Infrastructure resolvers map platform-specific topics to these cases.
 */
enum OrderWebhookIntent
{
    /** Full order entity sync (e.g. order.updated, order.finalized) */
    case Sync;

    /** Order status field changed */
    case StatusChanged;

    /** A refund was created against the order */
    case RefundCreated;

    /** Order was hard-deleted */
    case Deleted;
}
