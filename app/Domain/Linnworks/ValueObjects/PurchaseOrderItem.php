<?php

declare(strict_types=1);

namespace App\Domain\Linnworks\ValueObjects;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\IntId;
use App\Domain\ValueObjects\TaxRate;

/**
 * Linnworks purchase order item value object.
 *
 * Represents a single line item on a PO, sourced from the
 * Get_PurchaseOrder response PurchaseOrderItem array.
 *
 * Note: barcodeNumber uses string (not Gtin) because Linnworks returns
 * empty strings and non-standard barcodes that fail Gtin validation.
 *
 * @template-pattern Domain Value Object
 */
final readonly class PurchaseOrderItem
{
    /**
     * @param list<mixed> $skuGroupIds
     */
    public function __construct(
        // ── Identifiers ──
        public Guid $pkPurchaseItemId,
        public Guid $fkStockItemId,
        public ?IntId $stockItemIntId,

        // ── Quantities ──
        public int $quantity,
        public int $delivered,
        public int $packQuantity,
        public int $packSize,

        // ── Financial ──
        public float $cost,
        public float $tax,
        public TaxRate $taxRate,

        // ── Product ──
        public Sku $sku,
        public string $itemTitle,
        public string $barcodeNumber,
        public string $supplierCode,
        public string $supplierBarcode,

        // ── Physical ──
        public float $dimHeight,
        public float $dimWidth,
        public float $dimDepth,

        // ── State ──
        public bool $isDeleted,
        public int $inventoryTrackingType,
        public int $sortOrder,

        // ── Warehouse ──
        public int $boundToOpenOrdersItems,
        public int $quantityBoundToOpenOrdersItems,

        // ── Other ──
        public array $skuGroupIds = [],
        public ?string $binRack = null,
    ) {}
}
