<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use App\Domain\Shared\Money\ValueObjects\Money;

/**
 * Catalog-domain projection of a product supplier relationship.
 *
 * Read-only view of supplier data for a specific product (SKU).
 * Distinct from Inventory-domain StockItemSupplier — this is a
 * product-centric projection for API responses.
 */
final readonly class ProductSupplier
{
    public function __construct(
        public string $supplierName,
        public ?Money $purchasePrice,
        public bool $isDefault,
        public ?string $code = null,
        public ?Gtin $supplierBarcode = null,
        public ?int $leadTime = null,
        public ?int $supplierMinOrderQty = null,
        public ?int $supplierPackSize = null,
        public ?Money $minPrice = null,
        public ?Money $maxPrice = null,
        public ?Money $averagePrice = null,
        public ?float $averageLeadTime = null,
    ) {}

    /**
     * Serialize to API-friendly array.
     *
     * @return array{supplier_name: string, purchase_price: float|null, is_default: bool, code: string|null, supplier_barcode: string|null, lead_time: int|null, supplier_min_order_qty: int|null, supplier_pack_size: int|null, min_price: float|null, max_price: float|null, average_price: float|null, average_lead_time: float|null}
     */
    public function toArray(): array
    {
        return [
            'supplier_name' => $this->supplierName,
            'purchase_price' => $this->purchasePrice?->toNet(),
            'is_default' => $this->isDefault,
            'code' => $this->code,
            'supplier_barcode' => $this->supplierBarcode?->value,
            'lead_time' => $this->leadTime,
            'supplier_min_order_qty' => $this->supplierMinOrderQty,
            'supplier_pack_size' => $this->supplierPackSize,
            'min_price' => $this->minPrice?->toNet(),
            'max_price' => $this->maxPrice?->toNet(),
            'average_price' => $this->averagePrice?->toNet(),
            'average_lead_time' => $this->averageLeadTime,
        ];
    }
}
