<?php

declare(strict_types=1);

namespace App\Application\Linnworks\UseCases\PurchaseOrder;

use App\Application\Linnworks\DTOs\PurchaseOrder\DesiredExtendedPropertyDTO;
use App\Application\Linnworks\DTOs\PurchaseOrder\PurchaseOrderLineItemDTO;
use App\Domain\ValueObjects\Guid;
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
        public array $items,
        public string $currency = 'GBP',
        public string $supplierReferenceNumber = '',
        public ?int $unitAmountTaxIncludedType = null,
        public ?DateTimeImmutable $dateOfPurchase = null,
        public ?DateTimeImmutable $quotedDeliveryDate = null,
        public float $postagePaid = 0.00,
        public float $shippingTaxRate = 20.00,
        public float $conversionRate = 1.0,
        public array $extendedProperties = [],
        public ?string $externalInvoiceNumber = null,
    ) {}
}
