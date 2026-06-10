<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldValueList;
use App\Domain\Catalog\Filters\ValueObjects\ProductFilter;
use App\Domain\Catalog\Product\Concerns\BasicProductTrait;
use App\Domain\Catalog\Product\Contracts\BasicProductInterface;
use App\Infrastructure\Shopwired\Factories\ProductDomainFactory;
use DateTimeImmutable;
use Webmozart\Assert\Assert;

/**
 * Product Value Object.
 *
 * Represents a product with core business-relevant properties.
 * Excludes unused ShopWired fields (freeDelivery, deliveryPrice, isNew, isBundle, isPreOrder, outOfStockStatus).
 *
 * **Custom Fields**: Products have two custom field representations:
 * - `rawCustomFields`: Raw name → value map for storage/persistence only.
 * - `customFields`: Typed values from CustomFieldDefinitionRegistry (populated on DB read)
 *
 * **Filters**: Products have two filter representations (same pattern):
 * - `rawFilters`: Raw optionNo → values map for storage (always populated)
 * - `filters`: Typed ProductFilter values from FilterGroupRegistry (populated on read)
 *
 * **Creation**:
 * - Write path: {@see ProductDomainFactory::fromResponse()}
 * - Read path: {@see ProductDomainFactory::fromModel()}
 *
 * @see https://shopwired.readme.io/reference/getproduct
 */
final readonly class Product implements BasicProductInterface
{
    use BasicProductTrait;
    /**
     * @param int $id ShopWired product ID (external identifier)
     * @param string|null $sku Master SKU
     * @param Gtin|null $gtin Global Trade Item Number (barcode)
     * @param string $title Product title
     * @param string|null $description HTML description
     * @param string $slug URL slug
     * @param string $url Full product URL
     * @param float $price Selling price
     * @param float|null $costPrice Cost/wholesale price
     * @param float|null $salePrice Discounted price (null = no sale)
     * @param float|null $comparePrice RRP / "Was" price for display
     * @param int $stock Master stock quantity (0 if product has variations)
     * @param bool $isActive Published/visible
     * @param bool $vatExclusive Price excludes VAT
     * @param bool $vatRelief VAT relief eligible
     * @param float|null $weight Weight in configured unit (TODO: replace with Weight value object post-merge)
     * @param string|null $metaTitle SEO title
     * @param string|null $metaDescription SEO description
     * @param list<int> $categoryIds ShopWired category IDs
     * @param list<ProductVariation>|null $variations Product variations (null=not provided, []=none)
     * @param list<ProductImage> $images Product images
     * @param array<string, mixed> $rawCustomFields Raw custom field data (name => value) for storage
     * @param CustomFieldValueList $customFields Typed custom field values (populated on read)
     * @param array<int|string, list<string>> $rawFilters Raw filter data (optionNo => values) for storage
     * @param list<ProductFilter> $filters Typed filter values (populated on read)
     * @param int|null $sortOrder ShopWired sort order (null = unknown/not fetched)
     * @param DateTimeImmutable $createdAt ShopWired creation timestamp
     * @param DateTimeImmutable $updatedAt ShopWired last update timestamp
     */
    public function __construct(
        public int $id,
        public ?string $sku,
        public ?Gtin $gtin,
        public string $title,
        public ?string $description,
        public string $slug,
        public string $url,
        public float $price,
        public ?float $costPrice,
        public ?float $salePrice,
        public ?float $comparePrice,
        public int $stock,
        public bool $isActive,
        public bool $vatExclusive,
        public bool $vatRelief,
        public ?float $weight,
        public ?string $metaTitle,
        public ?string $metaDescription,
        public array $categoryIds,
        public ?array $variations,
        public array $images,
        public array $rawCustomFields,
        public CustomFieldValueList $customFields,
        public array $rawFilters,
        public array $filters,
        public ?int $sortOrder,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        Assert::greaterThan($id, 0, 'Product ID must be positive');
        Assert::notEmpty($title, 'Product title cannot be empty');
        Assert::notEmpty($slug, 'Product slug cannot be empty');
        Assert::greaterThanEq($price, 0, 'Price cannot be negative');
        // Note: Stock can be negative in ShopWired (e.g., backorders)
    }

    public function hasVariations(): bool
    {
        return $this->variations !== null && $this->variations !== [];
    }

    /**
     * Get total stock across all variations, or master stock if no variations.
     */
    public function totalStock(): int
    {
        if ($this->variations === null || $this->variations === []) {
            return $this->stock;
        }

        return \array_sum(\array_map(
            static fn(ProductVariation $v): int => $v->stock,
            $this->variations,
        ));
    }

    // BasicProductInterface implementation (isOnSale, effectivePrice provided by BasicProductTrait)

    public function sku(): ?string
    {
        return $this->sku;
    }

    public function price(): float
    {
        return $this->price;
    }

    public function costPrice(): ?float
    {
        return $this->costPrice;
    }

    public function salePrice(): ?float
    {
        return $this->salePrice;
    }

    public function weight(): ?float
    {
        return $this->weight;
    }

    public function isInStock(): bool
    {
        return $this->totalStock() > 0;
    }

    public function getStockLevel(): int
    {
        return $this->totalStock();
    }

    /** @return list<Sku> */
    public function allSkus(): array
    {
        $skus = [];

        if ($this->sku !== null && $this->sku !== '') {
            $skus[] = Sku::fromTrusted($this->sku);
        }

        foreach ($this->variations ?? [] as $variation) {
            if ($variation->sku !== null && $variation->sku !== '') {
                $skus[] = Sku::fromTrusted($variation->sku);
            }
        }

        return $skus;
    }

    public function primaryImage(): ?ProductImage
    {
        return $this->images[0] ?? null;
    }

    /** A sale is active when salePrice is set, > 0, and < regular price. */
    public static function isSaleActive(?float $salePrice, float $price): bool
    {
        return $salePrice !== null && $salePrice > 0 && $salePrice < $price;
    }

    /**
     * Get all SKUs with an active sale price (master + variations).
     *
     * @return list<Sku>
     */
    public function allOnSaleSkus(): array
    {
        $skus = [];

        if ($this->sku !== null && $this->sku !== '' && self::isSaleActive($this->salePrice, $this->price)) {
            $skus[] = Sku::fromTrusted($this->sku);
        }

        return [...$skus, ...ProductVariation::onSaleSkus($this->variations ?? [], $this->price)];
    }

    public function getFilter(string $title): ?ProductFilter
    {
        return \array_find(
            $this->filters,
            static fn(ProductFilter $filter): bool => $filter->title() === $title,
        );
    }

}
