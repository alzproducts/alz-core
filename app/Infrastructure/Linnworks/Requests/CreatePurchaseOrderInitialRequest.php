<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Requests;

use App\Application\Linnworks\UseCases\PurchaseOrder\CreatePurchaseOrderCommand;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderReference;
use DateTimeImmutable;

/**
 * Structural mapping for the Linnworks Create_PurchaseOrder_Initial API endpoint.
 *
 * Date default resolution ($command->dateOfPurchase ?? new DateTimeImmutable()) is
 * handled by the client before calling the factory, keeping this class pure/deterministic.
 *
 * Nullable fields are preserved as-is (no null filtering) to maintain exact wire format.
 */
final readonly class CreatePurchaseOrderInitialRequest
{
    private function __construct(
        private string $fkSupplierId,
        private string $fkLocationId,
        private string $externalInvoiceNumber,
        private string $currency,
        private string $supplierReferenceNumber,
        private ?int $unitAmountTaxIncludedType,
        private string $dateOfPurchase,
        private ?string $quotedDeliveryDate,
        private float $postagePaid,
        private float $shippingTaxRate,
        private float $conversionRate,
    ) {}

    public static function fromCommand(
        CreatePurchaseOrderCommand $command,
        PurchaseOrderReference $reference,
        DateTimeImmutable $dateOfPurchase,
    ): self {
        return new self(
            fkSupplierId: $command->fkSupplierId->value,
            fkLocationId: $command->fkLocationId->value,
            externalInvoiceNumber: $reference->value,
            currency: $command->currency,
            supplierReferenceNumber: $command->supplierReferenceNumber,
            unitAmountTaxIncludedType: $command->unitAmountTaxIncludedType,
            dateOfPurchase: $dateOfPurchase->format('Y-m-d\TH:i:s'),
            quotedDeliveryDate: $command->quotedDeliveryDate?->format('Y-m-d\TH:i:s'),
            postagePaid: $command->postagePaid->toNet(),
            shippingTaxRate: $command->shippingTaxRate->percentage,
            conversionRate: $command->conversionRate,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fkSupplierId' => $this->fkSupplierId,
            'fkLocationId' => $this->fkLocationId,
            'ExternalInvoiceNumber' => $this->externalInvoiceNumber,
            'Currency' => $this->currency,
            'SupplierReferenceNumber' => $this->supplierReferenceNumber,
            'UnitAmountTaxIncludedType' => $this->unitAmountTaxIncludedType,
            'DateOfPurchase' => $this->dateOfPurchase,
            'QuotedDeliveryDate' => $this->quotedDeliveryDate,
            'PostagePaid' => $this->postagePaid,
            'ShippingTaxRate' => $this->shippingTaxRate,
            'ConversionRate' => $this->conversionRate,
        ];
    }
}
