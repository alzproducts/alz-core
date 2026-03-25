<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Catalog\Filters\ValueObjects\ProductFilter;
use App\Domain\Inventory\ValueObjects\Weight;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;

/**
 * Read-only API projection of a product.
 *
 * Separates the API view from the core write model (Product):
 * - Domain-typed properties (Money, IntId, Sku, Weight) instead of primitives
 * - Computed profitMargin, isOnSale, hasAnySale
 * - Drops rawCustomFields/rawFilters (storage-only)
 *
 * Constructed by ProductModelMapper::toReadDomain() and toApiDomain().
 */
final readonly class ProductView
{
    /** @var float|null Retail profit margin percentage, null when costPrice is null or price is zero */
    public ?float $profitMargin;

    /** @var bool Whether this product is currently on sale */
    public bool $isOnSale;

    /** @var bool Whether this product or any of its variations is on sale */
    public bool $hasAnySale;

    /**
     * @param IntId $id ShopWired product ID (external identifier)
     * @param Sku|null $sku Master SKU
     * @param Gtin|null $gtin Global Trade Item Number
     * @param string $title Product title
     * @param string|null $description HTML description
     * @param string $slug URL slug
     * @param string $url Full product URL
     * @param Money $price Selling price
     * @param Money|null $costPrice Cost price from Linnworks (null = unknown)
     * @param Money|null $salePrice Discounted price (null = no sale)
     * @param Money|null $comparePrice RRP / "Was" price
     * @param int $stock Master stock quantity
     * @param bool $isActive Published/visible
     * @param bool $vatExclusive Price excludes VAT
     * @param bool $vatRelief VAT relief eligible
     * @param Weight|null $weight Weight measurement
     * @param string|null $metaTitle SEO title
     * @param string|null $metaDescription SEO description
     * @param list<IntId> $categoryIds ShopWired category IDs
     * @param list<ProductVariationView>|null $variations Variations (null = not loaded)
     * @param list<ProductImage> $images Product images
     * @param list<AbstractCustomFieldValue> $customFields Typed custom field values
     * @param list<ProductFilter> $filters Typed filter values
     * @param int|null $sortOrder ShopWired sort order
     * @param DateTimeImmutable $createdAt ShopWired creation timestamp
     * @param DateTimeImmutable $updatedAt ShopWired last update timestamp
     */
    public function __construct(
        public IntId $id,
        public ?Sku $sku,
        public ?Gtin $gtin,
        public string $title,
        public ?string $description,
        public string $slug,
        public string $url,
        public Money $price,
        public ?Money $costPrice,
        public ?Money $salePrice,
        public ?Money $comparePrice,
        public int $stock,
        public bool $isActive,
        public bool $vatExclusive,
        public bool $vatRelief,
        public ?Weight $weight,
        public ?string $metaTitle,
        public ?string $metaDescription,
        public array $categoryIds,
        public ?array $variations,
        public array $images,
        public array $customFields,
        public array $filters,
        public ?int $sortOrder,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        $this->isOnSale = self::isSaleActive($this->salePrice, $this->price);
        $this->profitMargin = self::retailMargin($this->price, $this->costPrice);
        $this->hasAnySale = $this->isOnSale || self::anyVariationOnSale($this->variations);
    }

    /**
     * Single source of truth for whether a sale is active (Money-typed variant).
     *
     * Mirrors {@see Product::isSaleActive()} for domain-typed prices.
     * A sale is active when salePrice is set, non-zero, and less than regular price.
     */
    public static function isSaleActive(?Money $salePrice, Money $price): bool
    {
        return $salePrice !== null
            && ! $salePrice->isZero()
            && $salePrice->isLessThan($price);
    }

    /**
     * Calculate retail profit margin: (price - costPrice) / price × 100.
     *
     * Returns null when cost is unknown or price is zero (division guard).
     * Uses gross (tax-inclusive) values for both sides of the calculation.
     *
     * @return float|null Margin percentage, rounded to 2 decimal places
     */
    public static function retailMargin(Money $price, ?Money $costPrice): ?float
    {
        if ($costPrice === null || $price->isZero()) {
            return null;
        }

        $priceGross = $price->toGross();
        $costGross = $costPrice->toGross();

        return \round(($priceGross - $costGross) / $priceGross * 100, 2);
    }

    /**
     * Check if any variation in the list is on sale.
     *
     * @param list<ProductVariationView>|null $variations
     */
    private static function anyVariationOnSale(?array $variations): bool
    {
        if ($variations === null || $variations === []) {
            return false;
        }

        return \array_any(
            $variations,
            static fn(ProductVariationView $v): bool => $v->isOnSale,
        );
    }
}
