<?php

declare(strict_types=1);

namespace App\Domain\Inventory\ValueObjects;

use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\Guid;

/**
 * Parameters for linking a supplier to a stock item.
 *
 * Encapsulates the supplier-specific data needed to create a supplier stat
 * in Linnworks, keeping the identifier separate as it varies by call site.
 */
final readonly class SupplierLinkParams
{
    public function __construct(
        public Guid $supplierId,
        public ?Money $purchasePrice,
        public ?string $supplierCode = null,
        public bool $isDefault = false,
    ) {}
}
