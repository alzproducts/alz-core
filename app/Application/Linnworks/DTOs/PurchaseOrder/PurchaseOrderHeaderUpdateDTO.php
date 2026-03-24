<?php

declare(strict_types=1);

namespace App\Application\Linnworks\DTOs\PurchaseOrder;

use App\Application\Linnworks\UseCases\PurchaseOrder\UpdatePurchaseOrderHeaderCommand;
use App\Domain\Linnworks\Enums\PurchaseOrderStatus;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderHeader;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\TaxRate;
use DateTimeImmutable;

/**
 * Parameters for updating a purchase order header via Linnworks API.
 *
 * Contains only the fields the API accepts for header updates.
 * The Linnworks API requires the full header object — even unchanged fields
 * must be included or they'll be cleared.
 */
final readonly class PurchaseOrderHeaderUpdateDTO
{
    public function __construct(
        public Guid $pkPurchaseId,
        public Guid $fkSupplierId,
        public Guid $fkLocationId,
        public string $externalInvoiceNumber,
        public PurchaseOrderStatus $status,
        public string $currency,
        public string $supplierReferenceNumber,
        public int $unitAmountTaxIncludedType,
        public Money $postagePaid,
        public TaxRate $shippingTaxRate,
        public float $conversionRate,
        public ?DateTimeImmutable $quotedDeliveryDate = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forApi(): array
    {
        return [
            'pkPurchaseID' => $this->pkPurchaseId->value,
            'fkSupplierId' => $this->fkSupplierId->value,
            'fkLocationId' => $this->fkLocationId->value,
            'ExternalInvoiceNumber' => $this->externalInvoiceNumber,
            'Status' => $this->status->value,
            'Currency' => $this->currency,
            'SupplierReferenceNumber' => $this->supplierReferenceNumber,
            'UnitAmountTaxIncludedType' => $this->unitAmountTaxIncludedType,
            'PostagePaid' => $this->postagePaid->toNet(),
            'ShippingTaxRate' => $this->shippingTaxRate->percentage,
            'ConversionRate' => $this->conversionRate,
            'QuotedDeliveryDate' => $this->quotedDeliveryDate?->format('Y-m-d\TH:i:s'),
        ];
    }

    /**
     * Build from current header state with command overrides applied.
     */
    public static function fromHeaderWithOverrides(
        PurchaseOrderHeader $header,
        UpdatePurchaseOrderHeaderCommand $command,
    ): self {
        return new self(
            pkPurchaseId: $header->pkPurchaseId,
            fkSupplierId: $header->fkSupplierId,
            fkLocationId: $header->fkLocationId,
            externalInvoiceNumber: $header->externalInvoiceNumber,
            status: $header->status,
            currency: $header->currency,
            supplierReferenceNumber: $command->supplierReferenceNumber ?? $header->supplierReferenceNumber,
            unitAmountTaxIncludedType: $header->unitAmountTaxIncludedType,
            postagePaid: $command->postagePaid ?? $header->postagePaid,
            shippingTaxRate: $header->shippingTaxRate,
            conversionRate: $header->conversionRate,
            quotedDeliveryDate: $command->quotedDeliveryDate ?? $header->quotedDeliveryDate,
        );
    }
}
