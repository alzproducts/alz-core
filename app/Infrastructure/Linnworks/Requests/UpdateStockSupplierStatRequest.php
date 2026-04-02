<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Requests;

use App\Domain\Inventory\ValueObjects\StockItemSupplier;
use Webmozart\Assert\Assert;

/**
 * Structural mapping for the Linnworks UpdateStockSupplierStat API endpoint.
 *
 * Accepts complete domain VOs (UseCase handles all resolution and merging),
 * converts all 15 supplier-stat fields to the PascalCase wire format.
 */
final readonly class UpdateStockSupplierStatRequest
{
    private function __construct(
        private string $stockItemId,
        private ?int $stockItemIntId,
        private string $supplierId,
        private string $supplier,
        private ?string $code,
        private ?string $supplierBarcode,
        private float $purchasePrice,
        private bool $isDefault,
        private ?int $leadTime,
        private ?string $supplierCurrency,
        private ?float $minPrice,
        private ?float $maxPrice,
        private ?float $averagePrice,
        private ?int $averageLeadTime,
        private ?int $supplierMinOrderQty,
        private ?int $supplierPackSize,
    ) {}

    public static function fromDomain(StockItemSupplier $supplier): self
    {
        Assert::notNull($supplier->stockItemId, 'StockItemSupplier must have stockItemId to update Linnworks');
        Assert::notNull($supplier->purchasePrice, 'StockItemSupplier must have purchasePrice to update Linnworks');

        return new self(
            stockItemId: $supplier->stockItemId->value,
            stockItemIntId: $supplier->stockItemIntId?->value,
            supplierId: $supplier->supplierId->value,
            supplier: $supplier->supplierName,
            code: $supplier->code,
            supplierBarcode: $supplier->supplierBarcode,
            purchasePrice: $supplier->purchasePrice->toNet(),
            isDefault: $supplier->isDefault,
            leadTime: $supplier->leadTime,
            supplierCurrency: $supplier->supplierCurrency,
            minPrice: $supplier->minPrice?->toNet(),
            maxPrice: $supplier->maxPrice?->toNet(),
            averagePrice: $supplier->averagePrice?->toNet(),
            averageLeadTime: $supplier->averageLeadTime,
            supplierMinOrderQty: $supplier->supplierMinOrderQty,
            supplierPackSize: $supplier->supplierPackSize,
        );
    }

    /**
     * Build the full itemSuppliers payload for a bulk update.
     *
     * @param list<StockItemSupplier> $supplierStats
     *
     * @return list<array<string, mixed>>
     */
    public static function buildBulkPayload(array $supplierStats): array
    {
        return \array_map(
            static fn(StockItemSupplier $stat): array => self::fromDomain($stat)->toArray(),
            $supplierStats,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'StockItemId' => $this->stockItemId,
            'StockItemIntId' => $this->stockItemIntId,
            'SupplierID' => $this->supplierId,
            'Supplier' => $this->supplier,
            'Code' => $this->code,
            'SupplierBarcode' => $this->supplierBarcode,
            'PurchasePrice' => $this->purchasePrice,
            'IsDefault' => $this->isDefault,
            'LeadTime' => $this->leadTime,
            'SupplierCurrency' => $this->supplierCurrency,
            'MinPrice' => $this->minPrice,
            'MaxPrice' => $this->maxPrice,
            'AveragePrice' => $this->averagePrice,
            'AverageLeadTime' => $this->averageLeadTime,
            'SupplierMinOrderQty' => $this->supplierMinOrderQty,
            'SupplierPackSize' => $this->supplierPackSize,
        ];
    }
}
