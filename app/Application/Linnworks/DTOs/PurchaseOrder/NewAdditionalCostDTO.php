<?php

declare(strict_types=1);

namespace App\Application\Linnworks\DTOs\PurchaseOrder;

/**
 * Data for adding a new additional cost to a purchase order.
 *
 * Server computes tax, totalLineCost, and allocation from these inputs.
 */
final readonly class NewAdditionalCostDTO
{
    public function __construct(
        public int $additionalCostTypeId,
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
            'AdditionalCostTypeId' => $this->additionalCostTypeId,
            'SubTotalLineCost' => $this->subTotalLineCost,
            'TaxRate' => $this->taxRate,
            'Reference' => $this->reference,
            'Currency' => $this->currency,
            'ConversionRate' => $this->conversionRate,
        ];
    }
}
