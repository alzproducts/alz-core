<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
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
     * @param list<int> $categoryIds
     * @param list<int> $mainCategoryIds
     * @param list<ProductVariationView>|null $variations
     * @param list<ProductImage> $images
     * @param list<AbstractCustomFieldValue> $customFields
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
        public ?float $profitMargin,
        public bool $isActive,
        public bool $vatExclusive,
        public bool $vatRelief,
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
        ProductViewMeta $meta,
        bool $hasAnyVariationOnSale,
        int $parentAvailableStock,
        int $parentPhysicalStock,
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
    ) {
        $taxType = $vatExclusive ? TaxType::ZeroRated : TaxType::Inclusive;

        $this->id = IntId::from($externalId);
        $this->sku = $sku !== null && \mb_trim($sku) !== '' ? Sku::fromTrusted(\mb_trim($sku)) : null;
        $this->gtin = $gtin !== null && \mb_trim($gtin) !== '' ? Gtin::fromTrusted(\mb_trim($gtin)) : null;
        $this->price = Money::fromTaxType($price, $taxType);
        $this->costPrice = Money::nonZeroOrNull($costPrice, TaxType::Exclusive);
        $this->salePrice = Money::nonZeroOrNull($salePrice, $taxType);
        $this->rrp = Money::nonZeroOrNull($rrp, $taxType);
        $this->meta = $meta;
        $this->effectivePrice = Money::fromTaxType($effectivePrice, $taxType);
        $this->categoryIds = \array_map(static fn(int $id): IntId => IntId::from($id), $categoryIds);
        $this->mainCategoryIds = \array_map(static fn(int $id): IntId => IntId::from($id), $mainCategoryIds);
        $this->hasFreeDelivery = $freeDelivery !== null && ! $freeDelivery->isNone();
        $this->hasAnySale = $this->isOnSale || $hasAnyVariationOnSale;
        $this->defaultSupplier = $defaultSupplier;
        $this->createdAtFormatted = $createdAt->format(DateFormat::DEFAULT_DATE_FORMAT);
        $this->updatedAtFormatted = $updatedAt->format(DateFormat::DEFAULT_DATE_FORMAT);
        $this->stockLevel = Stock::fromParentAndVariants(
            $parentAvailableStock,
            $parentPhysicalStock,
            $variations,
        );
    }

    /**
     * Whether all sellable SKUs share the same selling price.
     *
     * Requires variations to be loaded (asserts non-null). Products with no
     * variations trivially have a single selling price.
     *
     * When the master product price is zero (variant-only pricing model),
     * variations are compared against each other instead of the master.
     */
    public function hasSingleSellingPrice(): bool
    {
        Assert::notNull($this->variations, 'variations must be loaded');

        if ($this->variations === []) {
            return true;
        }

        $reference = $this->price->isZero()
            ? $this->variations[0]->price
            : $this->price;

        return \array_all(
            $this->variations,
            static fn(ProductVariationView $v): bool => $v->price->amountEquals($reference),
        );
    }

    /**
     * Resolve the highest RRP across the master product and all variations.
     *
     * Requires variations to be loaded. Returns null when no SKU has an RRP set.
     */
    public function resolveHighestRrp(): ?Money
    {
        Assert::notNull($this->variations, 'variations must be loaded');

        $allRrps = [$this->rrp, ...\array_map(
            static fn(ProductVariationView $v): ?Money => $v->rrp,
            $this->variations,
        )];

        /** @var list<Money> $rrps */
        $rrps = \array_values(\array_filter($allRrps, static fn(?Money $rrp): bool => $rrp !== null));

        return $rrps === [] ? null : \array_reduce(
            $rrps,
            static fn(Money $max, Money $rrp): Money => $rrp->toGross() > $max->toGross() ? $rrp : $max,
            $rrps[0],
        );
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
        return \array_find(
            $this->customFields,
            static fn(AbstractCustomFieldValue $field): bool => $field->name() === $name,
        );
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
