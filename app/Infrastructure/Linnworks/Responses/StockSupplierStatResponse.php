<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses;

use App\Domain\Inventory\ValueObjects\StockItemSupplierStat;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Linnworks bulk supplier-stat response DTO.
 *
 * Maps fields from GetStockSupplierStatsBulk endpoint (full 15-field supplier-stat objects).
 * Used for the read step of the read-modify-write pattern in UpdateStockSupplierStat.
 *
 * @see https://apps.linnworks.net/Api/Class/linnworks-spa-commondata-Inventory-ClassBase-StockItemSupplierStat
 *
 * @template-pattern Infrastructure Response DTO
 */
#[MapInputName(PascalCaseMapper::class)]
final class StockSupplierStatResponse extends Data implements DomainConvertibleInterface
{
    public function __construct(
        public readonly string $stockItemId,
        public readonly ?int $stockItemIntId,
        #[MapInputName('SupplierID')]
        public readonly string $supplierId,
        /** @note Linnworks uses "Supplier" for the name, not "SupplierName" */
        public readonly string $supplier,
        public readonly ?string $code,
        public readonly ?string $supplierBarcode,
        public readonly float $purchasePrice,
        public readonly bool $isDefault,
        public readonly ?int $leadTime,
        public readonly ?string $supplierCurrency,
        public readonly ?float $minPrice,
        public readonly ?float $maxPrice,
        public readonly ?float $averagePrice,
        public readonly ?float $averageLeadTime,
        public readonly ?int $supplierMinOrderQty,
        public readonly ?int $supplierPackSize,
    ) {}

    public function toDomain(): StockItemSupplierStat
    {
        return new StockItemSupplierStat(
            stockItemId: new Guid($this->stockItemId),
            stockItemIntId: $this->stockItemIntId !== null && $this->stockItemIntId > 0
                ? IntId::from($this->stockItemIntId)
                : null,
            supplierId: new Guid($this->supplierId),
            supplierName: $this->supplier,
            code: $this->code,
            supplierBarcode: $this->supplierBarcode,
            purchasePrice: Money::exclusive($this->purchasePrice),
            isDefault: $this->isDefault,
            leadTime: $this->leadTime,
            supplierCurrency: $this->supplierCurrency,
            minPrice: $this->minPrice !== null ? Money::exclusive($this->minPrice) : null,
            maxPrice: $this->maxPrice !== null ? Money::exclusive($this->maxPrice) : null,
            averagePrice: $this->averagePrice !== null ? Money::exclusive($this->averagePrice) : null,
            averageLeadTime: $this->averageLeadTime,
            supplierMinOrderQty: $this->supplierMinOrderQty,
            supplierPackSize: $this->supplierPackSize,
        );
    }
}
