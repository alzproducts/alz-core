<?php

declare(strict_types=1);

namespace App\Domain\Inventory\ValueObjects;

use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\IntId;
use Webmozart\Assert\Assert;

/**
 * Supplier information attached to a stock item.
 *
 * Represents a supplier relationship from Linnworks Suppliers data.
 * Each stock item can have multiple suppliers, with one marked as default.
 * Used for the product_enrichment Mixpanel lookup table to provide
 * supplier context for SKU-based analytics.
 *
 * @template-pattern Domain Value Object
 */
final readonly class StockItemSupplier
{
    public function __construct(
        public Guid $supplierId,
        public string $supplierName,
        public ?string $code,
        public ?string $supplierBarcode,
        public ?Money $purchasePrice,
        public bool $isDefault,
        public ?int $leadTime,
        public ?string $supplierCurrency,
        public ?Money $minPrice,
        public ?Money $maxPrice,
        public ?Money $averagePrice,
        public ?Guid $stockItemId = null,
        public ?IntId $stockItemIntId = null,
        public ?int $averageLeadTime = null,
        public ?int $supplierMinOrderQty = null,
        public ?int $supplierPackSize = null,
    ) {
        Assert::notEmpty($supplierName, 'Supplier name cannot be empty');
    }

    /**
     * Return a new instance with the purchase price replaced.
     *
     * All other fields are copied as-is (immutable copy-on-write).
     */
    public function withPurchasePrice(Money $price): self
    {
        return new self(
            supplierId: $this->supplierId,
            supplierName: $this->supplierName,
            code: $this->code,
            supplierBarcode: $this->supplierBarcode,
            purchasePrice: $price,
            isDefault: $this->isDefault,
            leadTime: $this->leadTime,
            supplierCurrency: $this->supplierCurrency,
            minPrice: $this->minPrice,
            maxPrice: $this->maxPrice,
            averagePrice: $this->averagePrice,
            stockItemId: $this->stockItemId,
            stockItemIntId: $this->stockItemIntId,
            averageLeadTime: $this->averageLeadTime,
            supplierMinOrderQty: $this->supplierMinOrderQty,
            supplierPackSize: $this->supplierPackSize,
        );
    }
}
