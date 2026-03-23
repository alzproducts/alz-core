<?php

declare(strict_types=1);

namespace App\Domain\Linnworks\ValueObjects;

use App\Domain\ValueObjects\Guid;

/**
 * Linnworks purchase order extended property value object.
 *
 * Extended properties are key-value metadata on a PO.
 * Known property names: IsDropship, ShippingCalculated, ShippingMethod, SupplierInvoice.
 *
 * @template-pattern Domain Value Object
 */
final readonly class PurchaseOrderExtendedProperty
{
    public function __construct(
        public ?int $rowId,
        public ?Guid $purchaseId,
        public ?string $addedDateTime,
        public ?string $username,
        public string $propertyName,
        public string $propertyValue,
    ) {}
}
