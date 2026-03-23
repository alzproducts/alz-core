<?php

declare(strict_types=1);

namespace App\Application\Linnworks\DTOs\PurchaseOrder;

/**
 * Data for updating an existing additional cost on a purchase order.
 *
 * Requires the cost item ID to identify which cost to update.
 */
final readonly class AdditionalCostUpdateDTO
{
    public function __construct(
        public int $purchaseAdditionalCostItemId,
        public float $subTotalLineCost,
        public float $taxRate,
        public ?string $reference = null,
        public ?string $currency = null,
        public float $conversionRate = 1.0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forApi(): array
    {
        return [
            'PurchaseAdditionalCostItemId' => $this->purchaseAdditionalCostItemId,
            'SubTotalLineCost' => $this->subTotalLineCost,
            'TaxRate' => $this->taxRate,
            'Reference' => $this->reference,
            'Currency' => $this->currency,
            'ConversionRate' => $this->conversionRate,
        ];
    }
}
