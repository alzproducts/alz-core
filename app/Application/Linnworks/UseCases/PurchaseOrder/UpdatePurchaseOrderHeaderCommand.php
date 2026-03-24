<?php

declare(strict_types=1);

namespace App\Application\Linnworks\UseCases\PurchaseOrder;

use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\Guid;
use DateTimeImmutable;

/**
 * Command for updating purchase order header fields.
 *
 * Only non-null fields will be applied as overrides — null fields
 * retain their current values from the API.
 */
final readonly class UpdatePurchaseOrderHeaderCommand
{
    public function __construct(
        public Guid $purchaseId,
        public ?string $supplierReferenceNumber = null,
        public ?DateTimeImmutable $quotedDeliveryDate = null,
        public ?Money $postagePaid = null,
    ) {}

    /**
     * Get the list of fields being updated (for logging).
     *
     * @return list<string>
     */
    public function changedFields(): array
    {
        return \array_keys(\array_filter([
            'supplier_reference_number' => $this->supplierReferenceNumber,
            'quoted_delivery_date' => $this->quotedDeliveryDate,
            'postage_paid' => $this->postagePaid,
        ], static fn(mixed $v): bool => $v !== null));
    }
}
