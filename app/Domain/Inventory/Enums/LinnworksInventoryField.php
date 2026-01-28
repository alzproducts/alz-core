<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

/**
 * Valid field names for Linnworks UpdateInventoryItemField API.
 *
 * These are the exact values expected by the Linnworks API.
 *
 * @see https://apps.linnworks.net/Api/Method/Inventory-UpdateInventoryItemField
 */
enum LinnworksInventoryField: string
{
    case SKU = 'SKU';
    case Title = 'Title';
    case VariationGroupName = 'VariationGroupName';
    case RetailPrice = 'RetailPrice';
    case PurchasePrice = 'PurchasePrice';
    case Tracked = 'Tracked';
    case Barcode = 'Barcode';
    case Available = 'Available';
    case MinimumLevel = 'MinimumLevel';
    case InOrder = 'InOrder';
    case StockLevel = 'StockLevel';
    case StockValue = 'StockValue';
    case Due = 'Due';
    case BinRack = 'BinRack';
    case Category = 'Category';
    case Image = 'Image';
    case Weight = 'Weight';
    case DimHeight = 'DimHeight';
    case DimWidth = 'DimWidth';
    case DimDepth = 'DimDepth';
    case CreatedDate = 'CreatedDate';
    case ModifiedDate = 'ModifiedDate';
    case SerialNumberScanRequired = 'SerialNumberScanRequired';
    case BatchNumberScanRequired = 'BatchNumberScanRequired';
    case BatchType = 'BatchType';
    case JIT = 'JIT';
    case ReorderAmount = 'ReorderAmount';
    case ReorderDate = 'ReorderDate';
    case AverageConsumption = 'AverageConsumption';
    case DefaultSupplier = 'DefaultSupplier';
}
