<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Enums;

/**
 * Generic business intent for product webhook events.
 *
 * Platform-agnostic — does not contain ShopWired-specific topic strings.
 * Infrastructure resolvers map platform-specific topics to these cases.
 */
enum ProductWebhookIntent
{
    /** Full product entity sync (e.g. product.created, product.updated) */
    case Sync;

    /** Stock quantity changed on a specific SKU */
    case StockChanged;

    /** Product was hard-deleted */
    case Deleted;
}
