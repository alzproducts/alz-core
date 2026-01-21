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
 * **Pricing Semantics**:
 * - `price = null` → Inherit parent product's price
 * - `price = 0.00` → Temporarily removed from sale
 *
 * **External ID Instability**: Unlike parent products, variation external IDs
 * (`$id`) can change when product options are regenerated, variants are deleted
 * and recreated, or product structure is modified in ShopWired. Do NOT rely on
 * variation external_id for long-term tracking or analytics. Use SKU as the
 * stable identifier for variations.
 *
 * **SKU Nullable**: SKU can be null for legacy/inactive products that lack SKUs
 * in ShopWired. Active purchasable variants should always have SKUs.
 * See .ai/docs/known-issues.md "Product Variations with Missing SKUs".
 *
 * NOTE: Does not implement BasicProductInterface due to nullable price semantics.
 * See .ai/docs/known-issues.md "BasicProductInterface and ProductVariation".
 *
 * @see https://shopwired.readme.io/reference/getproduct
 */
final readonly class ProductVariation
{
    /** @var string|null Variation SKU (stable identifier - should be set for purchasable variants) */
    public ?string $sku;

    /**
     * @param int $id ShopWired variation ID (unstable - see class docblock)
     * @param int $productExternalId Parent product's ShopWired ID (for sync key)
     * @param string|null $sku Variation SKU (nullable for legacy data - see class docblock)
     * @param float|null $price Selling price (null = inherit parent price, 0.00 = temporarily removed from sale)
     * @param float|null $costPrice Cost/wholesale price
     * @param float|null $salePrice Discounted price (null = no sale)
     * @param int $stock Stock quantity
     * @param float|null $weight Weight in configured unit (TODO: replace with Weight value object post-merge)
     * @param Gtin|null $gtin Global Trade Item Number (barcode)
     * @param string|null $mpn Manufacturer Part Number
     * @param int|null $imageIndex Index into parent product's images array (null = no image)
     * @param list<ProductVariationOption> $options Option attributes (e.g., Size, Color)
     */
    public function __construct(
        public int $id,
        public int $productExternalId,
        ?string $sku,
        public ?float $price,
        public ?float $costPrice,
        public ?float $salePrice,
        public int $stock,
        public ?float $weight,
        public ?Gtin $gtin,
        public ?string $mpn,
        public ?int $imageIndex,
        public array $options = [],
    ) {
        Assert::greaterThan($id, 0, 'Variation ID must be positive');
        Assert::greaterThan($productExternalId, 0, 'Product external ID must be positive');
        Assert::nullOrGreaterThanEq($price, 0, 'Price cannot be negative');
        // Note: Stock can be negative in ShopWired (e.g., backorders)

        // Trim whitespace, treat whitespace-only as null
        $this->sku = $sku !== null && \mb_trim($sku) !== '' ? \mb_trim($sku) : null;
    }

    public function sku(): ?string
    {
        return $this->sku;
    }

    public function price(): ?float
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
        return $this->stock > 0;
    }

    public function getStockLevel(): int
    {
        return $this->stock;
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
