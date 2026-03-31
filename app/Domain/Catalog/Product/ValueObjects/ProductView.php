<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Catalog\Filters\ValueObjects\ProductFilter;
use App\Domain\Catalog\Product\Enums\FreeDeliveryType;
use App\Domain\Inventory\ValueObjects\Weight;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\IntId;
use App\Domain\ValueObjects\TaxType;
use DateTimeImmutable;

/**
 * Read-only API projection of a product.
 *
 * Self-constructs domain types from primitives matching the SQL view's flat row shape.
 * Complex typed collections (variations, customFields, filters, images, saleSettings)
 * are passed in already-typed by the assembler.
 *
 * Constructed by ProductViewAssembler::toViewDomain().
 */
final readonly class ProductView
{
    public IntId $id;

    public ?Sku $sku;

    public ?Gtin $gtin;

    public Money $price;

    public ?Money $costPrice;

    public ?Money $salePrice;

    public ?Money $comparePrice;

    public Money $effectivePrice;

    public ?Weight $weight;

    /** @var list<IntId> */
    public array $categoryIds;

    /** @var bool Whether this product has a free delivery designation */
    public bool $hasFreeDelivery;

    /** @var bool Whether this product or any of its variations is on sale */
    public bool $hasAnySale;

    /**
     * @param int $externalId ShopWired product ID
     * @param string|null $sku Master SKU
     * @param string|null $gtin Global Trade Item Number
     * @param string $title Product title
     * @param string|null $description HTML description
     * @param string $slug URL slug
     * @param string $url Full product URL
     * @param float $price Selling price
     * @param float|null $costPrice Cost price from Linnworks (null = unknown)
     * @param float|null $salePrice Discounted price (null = no sale)
     * @param float|null $comparePrice RRP / "Was" price
     * @param float $effectivePrice Selling price after sale logic
     * @param bool $isOnSale Whether this product is currently on sale (from view)
     * @param float|null $profitMargin Retail profit margin % (from view, null when cost unknown)
     * @param int $stock Master stock quantity
     * @param bool $isActive Published/visible
     * @param bool $vatExclusive Price excludes VAT
     * @param bool $vatRelief VAT relief eligible
     * @param float|null $weight Weight in kg
     * @param string|null $metaTitle SEO title
     * @param string|null $metaDescription SEO description
     * @param list<int> $categoryIds ShopWired category IDs
     * @param list<ProductVariationView>|null $variations Variations (null = not loaded)
     * @param list<ProductImage> $images Product images
     * @param list<AbstractCustomFieldValue> $customFields Typed custom field values
     * @param list<ProductFilter> $filters Typed filter values
     * @param SaleSettings|null $saleSettings Sale metadata (null = not loaded or no sale)
     * @param FreeDeliveryType|null $freeDelivery Free delivery tier (null = no designation)
     * @param int|null $sortOrder ShopWired sort order
     * @param DateTimeImmutable $createdAt ShopWired creation timestamp
     * @param DateTimeImmutable $updatedAt ShopWired last update timestamp
     */
    public function __construct(
        int $externalId,
        ?string $sku,
        ?string $gtin,
        public string $title,
        public ?string $description,
        public string $slug,
        public string $url,
        float $price,
        ?float $costPrice,
        ?float $salePrice,
        ?float $comparePrice,
        float $effectivePrice,
        public bool $isOnSale,
        public ?float $profitMargin,
        public int $stock,
        public bool $isActive,
        public bool $vatExclusive,
        public bool $vatRelief,
        ?float $weight,
        public ?string $metaTitle,
        public ?string $metaDescription,
        array $categoryIds,
        public ?array $variations,
        public array $images,
        public array $customFields,
        public array $filters,
        public ?int $sortOrder,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?SaleSettings $saleSettings = null,
        public ?FreeDeliveryType $freeDelivery = null,
    ) {
        $taxType = $vatExclusive ? TaxType::ZeroRated : TaxType::Inclusive;

        $this->id = IntId::from($externalId);
        $this->sku = $sku !== null && \mb_trim($sku) !== '' ? Sku::fromTrusted(\mb_trim($sku)) : null;
        $this->gtin = $gtin !== null ? Gtin::fromTrusted($gtin) : null;
        $this->price = Money::fromTaxType($price, $taxType);
        $this->costPrice = Money::nonZeroOrNull($costPrice, TaxType::Exclusive);
        $this->salePrice = Money::nonZeroOrNull($salePrice, $taxType);
        $this->comparePrice = Money::nonZeroOrNull($comparePrice, $taxType);
        $this->effectivePrice = Money::fromTaxType($effectivePrice, $taxType);
        $this->weight = $weight !== null ? Weight::kilogram($weight) : null;
        $this->categoryIds = \array_map(static fn(int $id): IntId => IntId::from($id), $categoryIds);
        $this->hasFreeDelivery = $freeDelivery !== null && ! $freeDelivery->isNone();
        $this->hasAnySale = $this->isOnSale || self::anyVariationOnSale($this->variations);
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
