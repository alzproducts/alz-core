<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses;

use App\Domain\Inventory\ValueObjects\StockItemSupplier;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Linnworks stock-item-supplier junction response DTO.
 *
 * Maps fields from GetStockItemsFull endpoint's Suppliers array (supplier-to-stock-item relationships).
 * The API field "Supplier" is the supplier name (not ID).
 *
 * @see https://apps.linnworks.net/Api/Class/linnworks-spa-commondata-Inventory-ClassBase-StockItemSupplierStat
 *
 * @template-pattern Infrastructure Response DTO
 */
#[MapInputName(PascalCaseMapper::class)]
final class StockItemSupplierResponse extends Data implements DomainConvertibleInterface
{
    public function __construct(
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
    ) {}

    public function toDomain(): StockItemSupplier
    {
        return new StockItemSupplier(
            supplierId: $this->supplierId,
            supplierName: $this->supplier,
            code: $this->code,
            supplierBarcode: $this->supplierBarcode,
            purchasePrice: $this->purchasePrice,
            isDefault: $this->isDefault,
            leadTime: $this->leadTime,
            supplierCurrency: $this->supplierCurrency,
            minPrice: $this->minPrice,
            maxPrice: $this->maxPrice,
            averagePrice: $this->averagePrice,
        );
    }
}
