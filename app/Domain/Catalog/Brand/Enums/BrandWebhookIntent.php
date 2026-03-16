<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Brand\Enums;

/**
 * Generic business intent for brand webhook events.
 *
 * Platform-agnostic — does not contain ShopWired-specific topic strings.
 * Infrastructure resolvers map platform-specific topics to these cases.
 */
enum BrandWebhookIntent
{
    /** Full brand entity sync (e.g. brand.created, brand.updated) */
    case Sync;

    /** Brand was hard-deleted */
    case Deleted;
}
