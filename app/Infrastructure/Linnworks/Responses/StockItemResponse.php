<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses;

use App\Domain\Inventory\ValueObjects\StockItem as DomainStockItem;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Linnworks stock item API response DTO.
 *
 * Maps fields from GetInventoryItemById endpoint (PascalCase format).
 * Converts to vendor-agnostic Domain StockItem via toDomain().
 *
 * Note: This endpoint doesn't return ItemDescription, CategoryName, or CreationDate.
 *
 * @template-pattern Infrastructure Response DTO
 */
#[MapInputName(PascalCaseMapper::class)]
final class StockItemResponse extends Data implements DomainConvertibleInterface
{
    public function __construct(
        public readonly string $stockItemId,
        public readonly int $stockItemIntId,
        public readonly string $itemNumber,
        public readonly string $itemTitle,
        public readonly string $barcodeNumber,
        public readonly int $quantity,
        public readonly int $inOrder,
        public readonly int $due,
        public readonly int $available,
        public readonly int $minimumLevel,
        public readonly float $purchasePrice,
        public readonly float $retailPrice,
        public readonly float $taxRate,
        public readonly ?float $weight,
        public readonly float $height,
        public readonly float $width,
        public readonly float $depth,
        public readonly string $categoryId,
        public readonly ?bool $isCompositeParent,
        public readonly bool $isBatchedStockType,
        public readonly int $inventoryTrackingType,
    ) {}

    public function toDomain(): DomainStockItem
    {
        return new DomainStockItem(
            sku: $this->itemNumber,
            title: $this->itemTitle,
            description: null,
            barcode: $this->barcodeNumber,
            quantity: $this->quantity,
            available: $this->available,
            inOrder: $this->inOrder,
            due: $this->due,
            minimumLevel: $this->minimumLevel,
            purchasePrice: $this->purchasePrice,
            retailPrice: $this->retailPrice,
            taxRate: $this->taxRate,
            weight: $this->weight,
            height: $this->height,
            width: $this->width,
            depth: $this->depth,
            isComposite: $this->isCompositeParent ?? false,
        );
    }
}
