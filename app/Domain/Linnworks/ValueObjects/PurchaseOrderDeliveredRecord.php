<?php

declare(strict_types=1);

namespace App\Domain\Linnworks\ValueObjects;

use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;

/**
 * Linnworks purchase order delivered record value object.
 *
 * Represents a delivery event against a PO item, sourced from the
 * Get_PurchaseOrder response DeliveredRecords array.
 *
 * @template-pattern Domain Value Object
 */
final readonly class PurchaseOrderDeliveredRecord
{
    public function __construct(
        public IntId $pkDeliveryRecordId,
        public Guid $fkPurchaseItemId,
        public Guid $fkStockLocationId,
        public float $unitCost,
        public int $deliveredQuantity,
        public ?DateTimeImmutable $createdDateTime,
    ) {}
}
