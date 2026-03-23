<?php

declare(strict_types=1);

namespace App\Application\Linnworks\DTOs\PurchaseOrder;

/**
 * Line item to add to a purchase order.
 */
final readonly class PurchaseOrderLineItemDTO
{
    public function __construct(
        public string $fkStockItemId,
        public int $quantity,
        public float $cost,
        public float $taxRate = 20.00,
        public ?int $packQuantity = null,
        public ?int $packSize = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forApi(string $purchaseId): array
    {
        return [
            'pkPurchaseId' => $purchaseId,
            'fkStockItemId' => $this->fkStockItemId,
            'Qty' => $this->quantity,
            'Cost' => $this->cost,
            'TaxRate' => $this->taxRate,
            'PackQuantity' => $this->packQuantity,
            'PackSize' => $this->packSize,
        ];
    }
}
