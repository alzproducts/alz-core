<?php

declare(strict_types=1);

namespace App\Domain\Catalog\CustomFields\Enums;

/**
 * How a ShopWired product-type custom field should be propagated to Linnworks stock items.
 *
 * - Single: write the value to the master Linnworks stock item only.
 * - AllVariants: also iterate every variation linked to the master and update each.
 */
enum LinnworksStockItemUpdateMode: string
{
    case Single = 'single';
    case AllVariants = 'all_variants';
}
