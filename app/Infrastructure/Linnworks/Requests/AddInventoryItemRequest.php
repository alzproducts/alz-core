<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Requests;

use App\Domain\Inventory\Commands\AddInventoryItemCommand;
use App\Domain\ValueObjects\Guid;

/**
 * Structural mapping for the Linnworks AddInventoryItem API endpoint.
 *
 * Tax rate mapping: Linnworks uses -1.0 to indicate "use default tax rate"
 * (standard rate). This is a Linnworks-specific wire convention, not business logic.
 */
final readonly class AddInventoryItemRequest
{
    private function __construct(
        private string $stockItemId,
        private string $itemNumber,
        private string $itemTitle,
        private string $categoryId,
        private float $retailPrice,
        private float $purchasePrice,
        private float $taxRate,
        private string $barcodeNumber,
    ) {}

    public static function fromCommand(Guid $stockItemId, Guid $categoryId, AddInventoryItemCommand $command): self
    {
        // Linnworks uses -1.0 to indicate "use default tax rate" (must be double, not int)
        $taxRate = $command->taxRate->isStandard() ? -1.0 : $command->taxRate->percentage;

        return new self(
            stockItemId: $stockItemId->value,
            itemNumber: $command->sku->value,
            itemTitle: $command->title,
            categoryId: $categoryId->value,
            retailPrice: $command->retailPrice->toGross(),
            purchasePrice: $command->purchasePrice?->toNet() ?? 0.0,
            taxRate: $taxRate,
            barcodeNumber: $command->barcode !== null ? $command->barcode->value : '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'StockItemId' => $this->stockItemId,
            'ItemNumber' => $this->itemNumber,
            'ItemTitle' => $this->itemTitle,
            'CategoryId' => $this->categoryId,
            'RetailPrice' => $this->retailPrice,
            'PurchasePrice' => $this->purchasePrice,
            'TaxRate' => $this->taxRate,
            'BarcodeNumber' => $this->barcodeNumber,
        ];
    }
}
