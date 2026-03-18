<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\TaxType;

/**
 * Retail pricing snapshot for a single SKU (master or variation).
 *
 * All prices are Money::inclusive() — ShopWired prices are always gross (tax-inclusive).
 * Preserves tax-type context for downstream consumers (profit calc, margin reporting).
 *
 * Reused throughout the price update feature:
 * - Pre-flight comparison (current vs proposed)
 * - Event payload (previous vs new)
 * - SCD2 snapshot computation (extract ->toGross() for decimal storage)
 * - Validation (salePrice < basePrice via ->toGross() comparison)
 */
final readonly class ProductRetailPricing
{
    /**
     * @param Money $basePrice Selling price (gross, tax-inclusive)
     * @param Money|null $salePrice Sale price (null = no sale active)
     */
    public function __construct(
        public Money $basePrice,
        public ?Money $salePrice = null,
    ) {}

    /**
     * Whether a sale is currently active (non-null, non-zero sale price).
     */
    public function saleActive(): bool
    {
        return $this->salePrice !== null && ! $this->salePrice->isZero();
    }

    /**
     * The price customers actually pay — sale price if active, otherwise base price.
     */
    public function effectivePrice(): Money
    {
        return $this->salePrice !== null && ! $this->salePrice->isZero()
            ? $this->salePrice
            : $this->basePrice;
    }

    /**
     * The tax type of the base price (for downstream storage).
     */
    public function taxType(): TaxType
    {
        return $this->basePrice->taxType;
    }
}
