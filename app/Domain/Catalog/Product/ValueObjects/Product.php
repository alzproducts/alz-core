<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use DateTimeImmutable;
use Webmozart\Assert\Assert;

/**
 * Product Value Object.
 *
 * Represents a product with core business-relevant properties.
 * Excludes unused ShopWired fields (freeDelivery, deliveryPrice, isNew, isBundle, isPreOrder, outOfStockStatus).
 *
 * @see https://shopwired.readme.io/reference/getproduct
 */
final readonly class Product
{
    /**
     * @param int $id ShopWired product ID (external identifier)
     * @param string|null $sku Master SKU
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
     * @param list<ProductVariation> $variations Product variations
     * @param list<ProductImage> $images Product images
     * @param DateTimeImmutable $createdAt ShopWired creation timestamp
     * @param DateTimeImmutable $updatedAt ShopWired last update timestamp
     */
    public function __construct(
        public int $id,
        public ?string $sku,
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
        public array $variations,
        public array $images,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        Assert::greaterThan($id, 0, 'Product ID must be positive');
        Assert::notEmpty($title, 'Product title cannot be empty');
        Assert::notEmpty($slug, 'Product slug cannot be empty');
        Assert::greaterThanEq($price, 0, 'Price cannot be negative');
        Assert::greaterThanEq($stock, 0, 'Stock cannot be negative');
    }

    /**
     * Check if this product has variations.
     */
    public function hasVariations(): bool
    {
        return $this->variations !== [];
    }

    /**
     * Get total stock across all variations, or master stock if no variations.
     */
    public function totalStock(): int
    {
        if (!$this->hasVariations()) {
            return $this->stock;
        }

        return \array_sum(\array_map(
            static fn(ProductVariation $v): int => $v->stock,
            $this->variations,
        ));
    }

    /**
     * Check if this product is on sale.
     */
    public function isOnSale(): bool
    {
        return $this->salePrice !== null && $this->salePrice < $this->price;
    }

    /**
     * Get the effective selling price (sale price if on sale, otherwise regular price).
     */
    public function effectivePrice(): float
    {
        if ($this->salePrice !== null && $this->salePrice < $this->price) {
            return $this->salePrice;
        }

        return $this->price;
    }

    /**
     * Check if this product is in stock.
     */
    public function isInStock(): bool
    {
        return $this->totalStock() > 0;
    }

    /**
     * Get the primary (first) image, if any.
     */
    public function primaryImage(): ?ProductImage
    {
        return $this->images[0] ?? null;
    }

    /**
     * Check if product belongs to a specific category.
     */
    public function isInCategory(int $categoryId): bool
    {
        return \in_array($categoryId, $this->categoryIds, true);
    }
}
