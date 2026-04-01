<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

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
        public ?float $purchasePrice,
        public bool $isDefault,
    ) {}

    /**
     * Serialize to API-friendly array.
     *
     * @return array{supplier_name: string, purchase_price: float|null, is_default: bool}
     */
    public function toArray(): array
    {
        return [
            'supplier_name' => $this->supplierName,
            'purchase_price' => $this->purchasePrice,
            'is_default' => $this->isDefault,
        ];
    }
}
