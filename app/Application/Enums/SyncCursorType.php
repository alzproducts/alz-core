<?php

declare(strict_types=1);

namespace App\Application\Enums;

/**
 * Well-known sync cursor identifiers.
 *
 * Centralizes cursor type strings used with SyncCursorRepositoryInterface
 * to prevent typos and enable IDE autocomplete.
 */
enum SyncCursorType: string
{
    /**
     * Cursor for the Linnworks → ShopWired delta stock sync.
     *
     * Tracks the most recent StockLevel.LastUpdateDate seen,
     * so the next delta query fetches only newer changes.
     */
    case LinnworksStockDelta = 'linnworks_stock_delta';

    /**
     * Cursor for the Linnworks StockItemFull incremental sync.
     *
     * Tracks the most recent StockItem.ModifiedDate seen,
     * so the next query fetches only items modified since then.
     */
    case LinnworksStockItemFull = 'linnworks_stock_item_full';
}
