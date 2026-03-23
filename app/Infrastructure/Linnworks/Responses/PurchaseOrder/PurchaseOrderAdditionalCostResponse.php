<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses\PurchaseOrder;

use App\Domain\Linnworks\ValueObjects\PurchaseOrderAdditionalCost;
use App\Domain\ValueObjects\TaxRate;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Linnworks PurchaseOrder additional cost API response DTO.
 *
 * Maps the Get_Additional_Cost response items.
 * Note: API returns items under lowercase `items` key (not `Items`).
 *
 * @template-pattern Infrastructure Response DTO
 */
#[MapInputName(PascalCaseMapper::class)]
final class PurchaseOrderAdditionalCostResponse extends Data implements DomainConvertibleInterface
{
    public function __construct(
        public readonly ?int $purchaseAdditionalCostItemId,
        public readonly ?int $additionalCostTypeId,
        public readonly ?string $reference,
        public readonly float $subTotalLineCost,
        public readonly float $taxRate,
        public readonly float $tax,
        public readonly ?string $currency,
        public readonly float $conversionRate,
        public readonly float $totalLineCost,
        public readonly bool $allocationLocked,
        public readonly ?string $additionalCostTypeName,
        public readonly bool $additionalCostTypeIsShippingType,
        public readonly bool $additionalCostTypeIsPartialAllocation,
        public readonly bool $print,
        public readonly ?string $allocationMethod,

        /** @var list<mixed>|null */
        public readonly ?array $costAllocation = null,
    ) {}

    public function toDomain(): PurchaseOrderAdditionalCost
    {
        return new PurchaseOrderAdditionalCost(
            purchaseAdditionalCostItemId: $this->purchaseAdditionalCostItemId,
            additionalCostTypeId: $this->additionalCostTypeId,
            reference: $this->reference,
            subTotalLineCost: $this->subTotalLineCost,
            taxRate: TaxRate::fromPercentage($this->taxRate),
            tax: $this->tax,
            currency: $this->currency,
            conversionRate: $this->conversionRate,
            totalLineCost: $this->totalLineCost,
            allocationLocked: $this->allocationLocked,
            additionalCostTypeName: $this->additionalCostTypeName,
            additionalCostTypeIsShippingType: $this->additionalCostTypeIsShippingType,
            additionalCostTypeIsPartialAllocation: $this->additionalCostTypeIsPartialAllocation,
            print: $this->print,
            allocationMethod: $this->allocationMethod,
            costAllocation: $this->costAllocation,
        );
    }
}
