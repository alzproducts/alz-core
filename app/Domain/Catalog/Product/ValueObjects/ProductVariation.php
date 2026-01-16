<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Product Variation Value Object.
 *
 * Represents a purchasable variant of a product (e.g., "Large, Red").
 * Contains pricing, stock, and option attributes.
 *
 * @see https://shopwired.readme.io/reference/getproduct
 */
final readonly class ProductVariation
{
    /**
     * @param int $id ShopWired variation ID
     * @param int $productExternalId Parent product's ShopWired ID (for sync key)
     * @param string|null $sku Variation SKU (may differ from master)
     * @param float $price Selling price
     * @param float|null $costPrice Cost/wholesale price
     * @param float|null $salePrice Discounted price (null = no sale)
     * @param int $stock Stock quantity
     * @param float|null $weight Weight in configured unit (TODO: replace with Weight value object post-merge)
     * @param Gtin|null $gtin Global Trade Item Number (barcode)
     * @param string|null $mpn Manufacturer Part Number
     * @param string|null $imageUrl Variation-specific image URL
     * @param list<ProductVariationOption> $options Option attributes (e.g., Size, Color)
     */
    public function __construct(
        public int $id,
        public int $productExternalId,
        public ?string $sku,
        public float $price,
        public ?float $costPrice,
        public ?float $salePrice,
        public int $stock,
        public ?float $weight,
        public ?Gtin $gtin,
        public ?string $mpn,
        public ?string $imageUrl,
        public array $options = [],
    ) {
        Assert::greaterThan($id, 0, 'Variation ID must be positive');
        Assert::greaterThan($productExternalId, 0, 'Product external ID must be positive');
        Assert::greaterThanEq($price, 0, 'Price cannot be negative');
        Assert::greaterThanEq($stock, 0, 'Stock cannot be negative');
    }

    /**
     * Check if this variation is on sale.
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
     * Check if this variation is in stock.
     */
    public function isInStock(): bool
    {
        return $this->stock > 0;
    }

    /**
     * Get display string of all options (e.g., "Size: Large, Color: Red").
     */
    public function optionsDisplayString(): string
    {
        return \implode(', ', \array_map(
            static fn(ProductVariationOption $opt): string => $opt->toDisplayString(),
            $this->options,
        ));
    }
}
