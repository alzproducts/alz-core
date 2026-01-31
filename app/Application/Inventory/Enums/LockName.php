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
}
