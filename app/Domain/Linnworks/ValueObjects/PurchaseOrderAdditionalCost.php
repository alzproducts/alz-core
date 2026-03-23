<?php

declare(strict_types=1);

namespace App\Domain\Linnworks\ValueObjects;

/**
 * Linnworks purchase order additional cost value object.
 *
 * Represents shipping and other cost line items on a PO (16 fields).
 *
 * @template-pattern Domain Value Object
 */
final readonly class PurchaseOrderAdditionalCost
{
    public function __construct(
        public ?int $purchaseAdditionalCostItemId,
        public ?int $additionalCostTypeId,
        public ?string $reference,
        public float $subTotalLineCost,
        public float $taxRate,
        public float $tax,
        public ?string $currency,
        public float $conversionRate,
        public float $totalLineCost,
        public bool $allocationLocked,
        public ?string $additionalCostTypeName,
        public bool $additionalCostTypeIsShippingType,
        public bool $additionalCostTypeIsPartialAllocation,
        public bool $print,
        public ?string $allocationMethod,

        /** @var list<mixed>|null */
        public ?array $costAllocation = null,
    ) {}
}
