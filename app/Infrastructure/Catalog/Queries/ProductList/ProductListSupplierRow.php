<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Queries\ProductList;

use App\Domain\Catalog\Product\ValueObjects\Gtin;
use App\Domain\Catalog\Product\ValueObjects\ProductSupplier;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\TaxType;

/**
 * A stock item's default supplier, as aggregated by {@see ProductListSql::SUPPLIER_FIELDS}.
 *
 * @internal
 */
final readonly class ProductListSupplierRow
{
    public string $supplierName;

    public ?float $purchasePrice;

    public bool $isDefault;

    public ?string $code;

    public ?string $supplierBarcode;

    public ?int $leadTime;

    public ?int $supplierMinOrderQty;

    public ?int $supplierPackSize;

    public ?float $minPrice;

    public ?float $maxPrice;

    public ?float $averagePrice;

    public ?float $averageLeadTime;

    /**
     * @param array<string, mixed> $row
     */
    public function __construct(array $row)
    {
        $this->supplierName = ProductListValue::string($row, 'supplier_name');
        $this->purchasePrice = ProductListValue::nullableFloat($row, 'purchase_price');
        $this->isDefault = ProductListValue::bool($row, 'is_default');
        $this->code = ProductListValue::nullableString($row, 'code');
        $this->supplierBarcode = ProductListValue::nullableString($row, 'supplier_barcode');
        $this->leadTime = ProductListValue::nullableInt($row, 'lead_time');
        $this->supplierMinOrderQty = ProductListValue::nullableInt($row, 'supplier_min_order_qty');
        $this->supplierPackSize = ProductListValue::nullableInt($row, 'supplier_pack_size');
        $this->minPrice = ProductListValue::nullableFloat($row, 'min_price');
        $this->maxPrice = ProductListValue::nullableFloat($row, 'max_price');
        $this->averagePrice = ProductListValue::nullableFloat($row, 'average_price');
        $this->averageLeadTime = ProductListValue::nullableFloat($row, 'average_lead_time');
    }

    public function toProductSupplier(): ProductSupplier
    {
        return new ProductSupplier(
            supplierName: $this->supplierName,
            purchasePrice: Money::nonZeroOrNull($this->purchasePrice, TaxType::Exclusive),
            isDefault: $this->isDefault,
            code: $this->code,
            supplierBarcode: Gtin::tryFromString($this->supplierBarcode),
            leadTime: $this->leadTime,
            supplierMinOrderQty: $this->supplierMinOrderQty,
            supplierPackSize: $this->supplierPackSize,
            minPrice: Money::nonZeroOrNull($this->minPrice, TaxType::Exclusive),
            maxPrice: Money::nonZeroOrNull($this->maxPrice, TaxType::Exclusive),
            averagePrice: Money::nonZeroOrNull($this->averagePrice, TaxType::Exclusive),
            averageLeadTime: $this->averageLeadTime,
        );
    }
}
