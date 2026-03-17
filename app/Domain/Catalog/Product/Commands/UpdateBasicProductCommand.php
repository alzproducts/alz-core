<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Commands;

use App\Domain\Catalog\Product\Enums\ProductType;
use App\Domain\Catalog\Product\ValueObjects\Gtin;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Inventory\ValueObjects\Weight;
use App\Domain\ValueObjects\IntId;
use App\Domain\ValueObjects\Money;

/**
 * Update non-price product/variation attributes via PUT endpoint.
 *
 * Accepts either SKU or IntId as identifier:
 * - SKU: Resolves via repository to find product or variation
 * - IntId: Direct variation ID lookup (for SKU-less variations)
 *
 * The optional `type` parameter enables targeted lookups when the caller
 * knows the entity type upfront, avoiding a dual-table search.
 *
 * Price/salePrice updates use the dedicated POST products/prices endpoint
 * via UpdatePriceCommand — the PUT endpoint silently ignores salePrice: 0.
 * costPrice stays here (works via PUT, not supported by POST).
 *
 * All update fields are nullable for partial updates.
 */
final readonly class UpdateBasicProductCommand
{
    /**
     * @param Sku|IntId $identifier Current SKU or variation ID to identify the target
     * @param ProductType|null $type Entity type for targeted lookup (null = search both tables)
     * @param Sku|null $newSku New SKU to set (null = no change)
     * @param Money|null $costPrice New cost price (null = no change)
     * @param Weight|null $weight New weight (null = no change)
     * @param Gtin|null $gtin New barcode (null = no change)
     */
    public function __construct(
        public Sku|IntId $identifier,
        public ?ProductType $type = null,
        public ?Sku $newSku = null,
        public ?Money $costPrice = null,
        public ?Weight $weight = null,
        public ?Gtin $gtin = null,
    ) {
        // Sku and IntId VOs self-validate in their constructors
    }

    public function hasAnyUpdate(): bool
    {
        return $this->newSku !== null
            || $this->costPrice !== null
            || $this->weight !== null
            || $this->gtin !== null;
    }
}
