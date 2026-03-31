<?php

declare(strict_types=1);

namespace App\Domain\Linnworks\ValueObjects;

use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\TaxRate;

/**
 * Linnworks purchase order item value object.
 *
 * Represents a single line item on a PO, containing only PO-native data.
 * Product enrichment data (SKU, title, dimensions, etc.) is available
 * via the stock_items table join on fkStockItemId.
 *
 * @template-pattern Domain Value Object
 */
final readonly class PurchaseOrderItem
{
    public function __construct(
        // ── Identifiers ──
        public Guid $pkPurchaseItemId,
        public Guid $fkStockItemId,

        // ── Quantities ──
        public int $quantity,
        public int $delivered,
        public int $packQuantity,
        public int $packSize,

        // ── Financial ──
        public float $cost,
        public float $tax,
        public TaxRate $taxRate,

        // ── State ──
        public int $sortOrder,
    ) {}
}
