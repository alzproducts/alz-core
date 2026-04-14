<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

/**
 * Fields on a Linnworks stock item that can be updated via UpdateInventoryItemField.
 *
 * Excludes fields managed by other dedicated endpoints or the Linnworks stock system:
 * - SKU: has dedicated updateSku() method
 * - Image: has dedicated addImage() method
 * - StockLevel, Available, InOrder, StockValue, Due: managed by Linnworks stock system
 * - CreatedDate, ModifiedDate: system timestamps (read-only)
 *
 * API field name mapping lives in Infrastructure (InventoryFieldUpdateClient::mapField()).
 */
enum InventoryUpdatableField
{
    case Category;
    case MinimumLevel;
    case JIT;
    case RetailPrice;
    case PurchasePrice;
    case BinRack;
    case Barcode;
    case Weight;
    case Title;
}
