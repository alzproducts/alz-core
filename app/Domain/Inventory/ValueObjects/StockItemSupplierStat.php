<?php

declare(strict_types=1);

namespace App\Domain\Inventory\ValueObjects;

use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\IntId;
use Webmozart\Assert\Assert;

/**
 * Complete supplier stat record from the Linnworks bulk stats API.
 *
 * Represents the full 15-field payload that UpdateStockSupplierStat expects.
 * Unlike {@see StockItemSupplier} (catalog context), this VO guarantees
 * non-null stockItemId and purchasePrice — required for API writes.
 *
 * @template-pattern Domain Value Object
 */
final readonly class StockItemSupplierStat
{
    public function __construct(
        public Guid $stockItemId,
        public ?IntId $stockItemIntId,
        public Guid $supplierId,
        public string $supplierName,
        public ?string $code,
        public ?string $supplierBarcode,
        public Money $purchasePrice,
        public bool $isDefault,
        public ?int $leadTime,
        public ?string $supplierCurrency,
        public ?Money $minPrice,
        public ?Money $maxPrice,
        public ?Money $averagePrice,
        public ?float $averageLeadTime,
        public ?int $supplierMinOrderQty,
        public ?int $supplierPackSize,
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
            stockItemId: $this->stockItemId,
            stockItemIntId: $this->stockItemIntId,
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
            averageLeadTime: $this->averageLeadTime,
            supplierMinOrderQty: $this->supplierMinOrderQty,
            supplierPackSize: $this->supplierPackSize,
        );
    }
}
