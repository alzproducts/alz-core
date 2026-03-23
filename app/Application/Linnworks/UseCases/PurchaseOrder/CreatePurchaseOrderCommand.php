<?php

declare(strict_types=1);

namespace App\Application\Linnworks\UseCases\PurchaseOrder;

use App\Application\Linnworks\DTOs\PurchaseOrder\DesiredExtendedPropertyDTO;
use App\Application\Linnworks\DTOs\PurchaseOrder\PurchaseOrderLineItemDTO;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderReference;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\TaxRate;
use DateTimeImmutable;

/**
 * Command for creating a complete purchase order.
 *
 * Encapsulates all data needed for the composite create operation:
 * initial PO creation + line items + optional extended properties.
 */
final readonly class CreatePurchaseOrderCommand
{
    /**
     * @param list<PurchaseOrderLineItemDTO> $items Line items to add after creation
     * @param list<DesiredExtendedPropertyDTO> $extendedProperties Optional EPs to add
     */
    public function __construct(
        public Guid $fkSupplierId,
        public Guid $fkLocationId,
        public PurchaseOrderReference $reference,
        public array $items,
        public string $currency,
        public string $supplierReferenceNumber,
        public ?int $unitAmountTaxIncludedType,
        public ?DateTimeImmutable $dateOfPurchase,
        public Money $postagePaid,
        public TaxRate $shippingTaxRate,
        public ?DateTimeImmutable $quotedDeliveryDate = null,
        public float $conversionRate = 1.0,
        public array $extendedProperties = [],
    ) {}
}
