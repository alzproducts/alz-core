<?php

declare(strict_types=1);

namespace App\Application\Inventory\Enums;

/**
 * Well-known distributed lock names.
 *
 * Centralizes lock identifiers used with LockManagerInterface to prevent
 * typos and enable IDE autocomplete across use cases.
 */
enum LockName: string
{
    /**
     * Prevents race conditions during SKU generation.
     *
     * Used when generating new SKUs from Linnworks and immediately
     * creating/updating inventory items to claim the SKU.
     */
    case SkuGeneration = 'sku-generation';

    /**
     * Ensures full and delta stock syncs run serially.
     *
     * Covers only the critical section: reading the local ShopWired DB snapshot,
     * computing differences, pushing updates to the ShopWired API, and writing
     * results back to the local DB. Linnworks reads happen outside this lock —
     * they are read-only and not affected by concurrent sync operations.
     *
     * Prevents stock ping-pong where concurrent syncs overwrite each other
     * with stock levels from different time windows.
     */
    case StockSync = 'stock-sync-to-shopwired';
}
