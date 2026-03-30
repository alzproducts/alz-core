<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses\PurchaseOrder;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderItem;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\IntId;
use App\Domain\ValueObjects\TaxRate;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Linnworks PurchaseOrder item API response DTO.
 *
 * Maps the Get_PurchaseOrder response PurchaseOrderItem array items.
 *
 * Note: barcodeNumber uses string (not Gtin) — Linnworks returns empty
 * strings and non-standard barcodes that fail Gtin validation.
 *
 * @template-pattern Infrastructure Response DTO
 */
#[MapInputName(PascalCaseMapper::class)]
final class PurchaseOrderItemResponse extends Data implements DomainConvertibleInterface
{
    /**
     * @param list<mixed> $skuGroupIds
     */
    public function __construct(
        #[MapInputName('pkPurchaseItemId')]
        public readonly string $pkPurchaseItemId,
        #[MapInputName('fkStockItemId')]
        public readonly string $fkStockItemId,
        public readonly ?int $stockItemIntId,
        public readonly int $quantity,
        public readonly int $delivered,
        public readonly int $packQuantity,
        public readonly int $packSize,
        public readonly float $cost,
        public readonly float $tax,
        public readonly float $taxRate,
        #[MapInputName('SKU')]
        public readonly string $sku,
        public readonly string $itemTitle,
        public readonly string $barcodeNumber,
        public readonly string $supplierCode,
        public readonly string $supplierBarcode,
        public readonly float $dimHeight,
        public readonly float $dimWidth,
        public readonly float $dimDepth,
        public readonly bool $isDeleted,
        public readonly int $inventoryTrackingType,
        public readonly int $sortOrder,
        public readonly int $boundToOpenOrdersItems,
        public readonly int $quantityBoundToOpenOrdersItems,
        public readonly array $skuGroupIds = [],
        public readonly string $binRack = '',
    ) {}

    /**
     * @throws InvalidApiResponseException When Sku validation fails
     */
    public function toDomain(): PurchaseOrderItem
    {
        return new PurchaseOrderItem(
            pkPurchaseItemId: Guid::fromTrusted($this->pkPurchaseItemId),
            fkStockItemId: Guid::fromTrusted($this->fkStockItemId),
            stockItemIntId: $this->stockItemIntId !== null && $this->stockItemIntId > 0
                ? IntId::fromTrusted($this->stockItemIntId)
                : null,
            quantity: $this->quantity,
            delivered: $this->delivered,
            packQuantity: $this->packQuantity,
            packSize: $this->packSize,
            cost: $this->cost,
            tax: $this->tax,
            taxRate: TaxRate::fromPercentage($this->taxRate),
            sku: Sku::fromTrusted($this->sku),
            itemTitle: $this->itemTitle,
            barcodeNumber: $this->barcodeNumber,
            supplierCode: $this->supplierCode,
            supplierBarcode: $this->supplierBarcode,
            dimHeight: $this->dimHeight,
            dimWidth: $this->dimWidth,
            dimDepth: $this->dimDepth,
            isDeleted: $this->isDeleted,
            inventoryTrackingType: $this->inventoryTrackingType,
            sortOrder: $this->sortOrder,
            binRack: $this->binRack,
            boundToOpenOrdersItems: $this->boundToOpenOrdersItems,
            quantityBoundToOpenOrdersItems: $this->quantityBoundToOpenOrdersItems,
            skuGroupIds: $this->skuGroupIds,
        );
    }
}
