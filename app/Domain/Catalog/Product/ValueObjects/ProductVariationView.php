<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use App\Domain\Inventory\ValueObjects\Weight;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\IntId;

/**
 * Read-only API projection of a product variation.
 *
 * Separates the API view from the core write model (ProductVariation):
 * - Domain-typed properties (Money, IntId, Sku, Weight) instead of primitives
 * - Always-resolved price (never null — inherits parent when variation doesn't override)
 * - Computed profitMargin and isOnSale
 *
 * Constructed by ProductVariationModelMapper::toReadDomain() after price resolution.
 */
final readonly class ProductVariationView
{
    /** @var float|null Retail profit margin percentage, null when costPrice is null or price is zero */
    public ?float $profitMargin;

    /** @var bool Whether this variation is currently on sale */
    public bool $isOnSale;

    /**
     * @param IntId $id ShopWired variation ID
     * @param Sku|null $sku Variation SKU (nullable for legacy data)
     * @param Gtin|null $gtin Global Trade Item Number
     * @param Money $price Selling price (always resolved — never null)
     * @param Money|null $costPrice Cost price from Linnworks (null = unknown)
     * @param Money|null $salePrice Discounted price (null = no sale)
     * @param int $stock Stock quantity
     * @param Weight|null $weight Weight measurement
     * @param string|null $mpn Manufacturer Part Number
     * @param int|null $imageIndex Index into parent product's images array
     * @param list<ProductVariationOption> $options Option attributes (e.g., Size, Color)
     */
    public function __construct(
        public IntId $id,
        public ?Sku $sku,
        public ?Gtin $gtin,
        public Money $price,
        public ?Money $costPrice,
        public ?Money $salePrice,
        public int $stock,
        public ?Weight $weight,
        public ?string $mpn,
        public ?int $imageIndex,
        public array $options,
    ) {
        $this->isOnSale = ProductView::isSaleActive($this->salePrice, $this->price);
        $this->profitMargin = ProductView::retailMargin($this->price, $this->costPrice);
    }
}
