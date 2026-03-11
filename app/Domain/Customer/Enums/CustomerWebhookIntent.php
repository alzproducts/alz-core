<?php

declare(strict_types=1);

namespace App\Domain\Customer\Enums;

/**
 * Generic business intent for customer webhook events.
 *
 * Platform-agnostic — does not contain ShopWired-specific topic strings.
 * Infrastructure resolvers map platform-specific topics to these cases.
 */
enum CustomerWebhookIntent
{
    /** Full customer entity sync (e.g. customer.created, customer.updated) */
    case Sync;

    /** Customer was hard-deleted */
    case Deleted;
}
