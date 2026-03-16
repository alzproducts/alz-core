<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Category\Enums;

/**
 * Generic business intent for category webhook events.
 *
 * Platform-agnostic — does not contain ShopWired-specific topic strings.
 * Infrastructure resolvers map platform-specific topics to these cases.
 */
enum CategoryWebhookIntent
{
    /** Full category entity sync (e.g. category.created, category.updated) */
    case Sync;

    /** Category was hard-deleted */
    case Deleted;
}
