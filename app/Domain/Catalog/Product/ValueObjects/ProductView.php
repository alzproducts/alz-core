<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldValueList;
use App\Domain\Catalog\Filters\ValueObjects\ProductFilter;
use App\Domain\Catalog\Product\Enums\FreeDeliveryType;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\DateFormat;
use App\Domain\ValueObjects\IntId;
use App\Domain\ValueObjects\TaxType;
use DateTimeImmutable;
use Webmozart\Assert\Assert;

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

    public ?Money $rrp;

    public ProductViewMeta $meta;

    public Money $effectivePrice;

    public ?float $profitMargin;

    /**
     * True when all sellable SKUs share the same selling price.
     *
     * Pre-computed from the fully-loaded variation list so it serialises on the
     * list API without requiring `?include=variations`.
     */
    public bool $hasSingleSellingPrice;

    /** @var list<IntId> */
    public array $categoryIds;

    /** @var list<IntId> */
    public array $mainCategoryIds;

    public bool $hasFreeDelivery;

    public bool $hasAnySale;

    public ?ProductSupplier $defaultSupplier;

    public string $createdAtFormatted;

    public string $updatedAtFormatted;

    /**
     * Aggregate stock level — sums across variations when present, otherwise
     * uses the parent's own values. See Stock::fromParentAndVariants().
     */
    public Stock $stockLevel;

    /**
     * Stock status flags promoted from ShopWired custom fields.
     *
     * Always present; individual properties are nullable when the underlying
     * custom field is unset on the product.
     */
    public StockStatus $stockStatus;

    /**
     * @param list<int> $categoryIds
     * @param list<int> $mainCategoryIds
     * @param list<ProductVariationView>|null $variations
     * @param list<ProductVariationView>|null $allVariations Fully-loaded variations for internal
     *        derivations (stock, price fallbacks). Independent of the public $variations gate.
     *        Not stored — derivation-only. Defaults to $variations when omitted.
     * @param list<ProductImage> $images
     * @param list<ProductFilter> $filters
     */
    public function __construct(
        int $externalId,
        ?string $sku,
        ?string $gtin,
        public string $title,
        public ?string $description,
        public string $slug,
        public ProductLinks $links,
        float $price,
        ?float $costPrice,
        ?float $salePrice,
        ?float $rrp,
        float $effectivePrice,
        public bool $isOnSale,
        ?float $profitMargin,
        public bool $isActive,
        public bool $vatExclusive,
        public bool $vatRelief,
        public ?string $metaTitle,
        public ?string $metaDescription,
        array $categoryIds,
        public ?array $variations,
        public array $images,
        public CustomFieldValueList $customFields,
        public array $filters,
        public ?int $sortOrder,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        ProductViewMeta $meta,
        bool $hasAnyVariationOnSale,
        int $parentAvailableStock,
        int $parentPhysicalStock,
        ?array $allVariations = null,
        public ?SaleSettings $saleSettings = null,
        public ?FreeDeliveryType $freeDelivery = null,
        /** @var list<ProductSupplier>|null */
        public ?array $suppliers = null,
        public ?ProductInventory $inventory = null,
        public ?ProductStock $stock = null,
        ?ProductSupplier $defaultSupplier = null,
        public ?bool $isComposite = null,
        /** @var list<int> */
        array $mainCategoryIds = [],
        public ?DateTimeImmutable $priceLastUpdatedAt = null,
        public ?DateTimeImmutable $costPriceLastUpdatedAt = null,
        public ?Popularity $popularity = null,
        ?string $discontinued = null,
        ?DateTimeImmutable $preorderDate = null,
        ?string $otherStockStatus = null,
    ) {
        $taxType = $vatExclusive ? TaxType::ZeroRated : TaxType::Inclusive;
        $derivationVariations = $allVariations ?? $variations;

        $this->id = IntId::from($externalId);
        $this->sku = $sku !== null && \mb_trim($sku) !== '' ? Sku::fromTrusted(\mb_trim($sku)) : null;
        $this->gtin = $gtin !== null && \mb_trim($gtin) !== '' ? Gtin::fromTrusted(\mb_trim($gtin)) : null;
        $this->salePrice = Money::nonZeroOrNull($salePrice, $taxType);
        $this->rrp = Money::nonZeroOrNull($rrp, $taxType);
        $this->meta = $meta;
        $this->categoryIds = \array_map(static fn(int $id): IntId => IntId::from($id), $categoryIds);
        $this->mainCategoryIds = \array_map(static fn(int $id): IntId => IntId::from($id), $mainCategoryIds);
        $this->hasFreeDelivery = $freeDelivery !== null && ! $freeDelivery->isNone();
        $this->hasAnySale = $this->isOnSale || $hasAnyVariationOnSale;
        $this->defaultSupplier = $defaultSupplier;
        $this->createdAtFormatted = $createdAt->format(DateFormat::DEFAULT_DATE_FORMAT);
        $this->updatedAtFormatted = $updatedAt->format(DateFormat::DEFAULT_DATE_FORMAT);

        $master = new MasterPricing(
            Money::fromTaxType($price, $taxType),
            Money::fromTaxType($effectivePrice, $taxType),
            Money::nonZeroOrNull($costPrice, TaxType::Exclusive),
            $profitMargin,
        );
        $pricing = ProductViewPricing::aggregate($master, $derivationVariations);
        $this->price = $pricing->price;
        $this->effectivePrice = $pricing->effectivePrice;
        $this->costPrice = $pricing->costPrice;
        $this->profitMargin = $pricing->profitMargin;
        $this->hasSingleSellingPrice = ProductViewPricing::hasSingleSellingPrice($pricing->price, $derivationVariations);
        $this->stockLevel = Stock::fromParentAndVariants($parentAvailableStock, $parentPhysicalStock, $derivationVariations);
        $this->stockStatus = new StockStatus($discontinued, $preorderDate, $otherStockStatus);
    }

    /**
     * Resolve the single RRP that applies uniformly across every sellable SKU,
     * or null when SKUs carry differing or missing RRPs.
     *
     * Shopwired stores one comparePrice per product — meaningful output
     * requires every sellable SKU (parent, where it has its own SKU, plus
     * each variation with a SKU) to share the same non-null RRP.
     */
    public function uniformRrp(): ?Money
    {
        Assert::notNull($this->variations, 'variations must be loaded');

        return self::pickUniform($this->collectSellableRrps());
    }

    /** @return list<?Money> */
    private function collectSellableRrps(): array
    {
        Assert::notNull($this->variations, 'variations must be loaded');

        $rrps = [];
        if ($this->sku !== null) {
            $rrps[] = $this->rrp;
        }
        foreach ($this->variations as $variation) {
            if ($variation->sku !== null) {
                $rrps[] = $variation->rrp;
            }
        }

        return $rrps;
    }

    /** @param list<?Money> $rrps */
    private static function pickUniform(array $rrps): ?Money
    {
        if ($rrps === [] || $rrps[0] === null) {
            return null;
        }

        $first = $rrps[0];
        foreach ($rrps as $rrp) {
            if ($rrp === null || ! $rrp->amountEquals($first)) {
                return null;
            }
        }

        return $first;
    }

    public function isInCategory(IntId $categoryId): bool
    {
        return \array_any(
            $this->categoryIds,
            static fn(IntId $id): bool => $id->equals($categoryId),
        );
    }

    public function getCustomField(string $name): ?AbstractCustomFieldValue
    {
        return $this->customFields->findByName($name);
    }

    public function hasCustomField(string $name): bool
    {
        return $this->getCustomField($name) !== null;
    }

    /** @return list<Sku> */
    public function allOnSaleSkus(): array
    {
        Assert::notNull($this->variations, 'variations must be loaded');

        $skus = [];

        if ($this->sku !== null && $this->isOnSale) {
            $skus[] = $this->sku;
        }

        foreach ($this->variations as $variation) {
            if ($variation->sku !== null && $variation->isOnSale) {
                $skus[] = $variation->sku;
            }
        }

        return $skus;
    }
}
