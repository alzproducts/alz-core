<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses\PurchaseOrder;

use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Linnworks\Enums\PurchaseOrderStatus;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderHeader;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\TaxRate;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use App\Infrastructure\Linnworks\Support\LinnworksDateParser;
use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Linnworks PurchaseOrder header API response DTO.
 *
 * Maps the Get_PurchaseOrder response to a typed structure.
 * API uses PascalCase with some inconsistencies (pkPurchaseID vs fkSupplierId).
 *
 * @template-pattern Infrastructure Response DTO
 */
#[MapInputName(PascalCaseMapper::class)]
final class PurchaseOrderHeaderResponse extends Data implements DomainConvertibleInterface
{
    public function __construct(
        #[MapInputName('pkPurchaseID')]
        public readonly string $pkPurchaseId,
        #[MapInputName('fkSupplierId')]
        public readonly string $fkSupplierId,
        #[MapInputName('fkLocationId')]
        public readonly string $fkLocationId,
        public readonly string $externalInvoiceNumber,
        public readonly string $status,
        public readonly bool $locked,
        public readonly int $lineCount,
        public readonly int $deliveredLinesCount,
        public readonly string $currency,
        public readonly string $supplierReferenceNumber,
        public readonly int $unitAmountTaxIncludedType,
        public readonly float $postagePaid,
        public readonly float $totalCost,
        public readonly float $taxPaid,
        public readonly float $shippingTaxRate,
        public readonly float $conversionRate,
        public readonly float $convertedShippingCost,
        public readonly float $convertedShippingTax,
        public readonly float $convertedOtherCost,
        public readonly float $convertedOtherTax,
        public readonly float $convertedGrandTotal,
        public readonly ?string $dateOfPurchase = null,
        public readonly ?string $dateOfDelivery = null,
        public readonly ?string $quotedDeliveryDate = null,
    ) {}

    /**
     * @throws InvalidApiResponseException When status value doesn't match a known PurchaseOrderStatus
     */
    public function toDomain(): PurchaseOrderHeader
    {
        $status = PurchaseOrderStatus::tryFrom($this->status);

        if ($status === null) {
            Log::critical('Linnworks API returned unknown purchase order status', [
                'status' => $this->status,
                'purchaseId' => $this->pkPurchaseId,
            ]);

            throw new InvalidApiResponseException(
                'Linnworks',
                "Unknown purchase order status: {$this->status}",
            );
        }

        return new PurchaseOrderHeader(
            pkPurchaseId: Guid::fromTrusted($this->pkPurchaseId),
            fkSupplierId: Guid::fromTrusted($this->fkSupplierId),
            fkLocationId: Guid::fromTrusted($this->fkLocationId),
            externalInvoiceNumber: $this->externalInvoiceNumber,
            status: $status,
            locked: $this->locked,
            lineCount: $this->lineCount,
            deliveredLinesCount: $this->deliveredLinesCount,
            currency: $this->currency,
            supplierReferenceNumber: $this->supplierReferenceNumber,
            unitAmountTaxIncludedType: $this->unitAmountTaxIncludedType,
            postagePaid: Money::exclusive($this->postagePaid),
            totalCost: $this->totalCost,
            taxPaid: $this->taxPaid,
            shippingTaxRate: $this->shippingTaxRate < 0 ? null : TaxRate::fromPercentage($this->shippingTaxRate), // -1 means "not set"
            conversionRate: $this->conversionRate,
            convertedShippingCost: $this->convertedShippingCost,
            convertedShippingTax: $this->convertedShippingTax,
            convertedOtherCost: $this->convertedOtherCost,
            convertedOtherTax: $this->convertedOtherTax,
            convertedGrandTotal: $this->convertedGrandTotal,
            dateOfPurchase: LinnworksDateParser::parse($this->dateOfPurchase),
            dateOfDelivery: LinnworksDateParser::parse($this->dateOfDelivery),
            quotedDeliveryDate: LinnworksDateParser::parse($this->quotedDeliveryDate),
        );
    }
}
