<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

/**
 * Well-known Linnworks extended property names.
 *
 * Extended properties store custom attributes on stock items.
 * This enum centralizes property names to prevent typos and enable IDE autocomplete.
 */
enum ExtendedPropertyName: string
{
    /**
     * ShopWired variation external ID.
     *
     * Links a Linnworks stock item back to its ShopWired variation.
     * Used for cross-system reconciliation and debugging.
     */
    case ShopId = 'ShopID';
}
