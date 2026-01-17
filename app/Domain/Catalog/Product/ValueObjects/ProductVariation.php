<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\Concerns\BasicProductTrait;
use App\Domain\Catalog\Product\Contracts\BasicProductInterface;
use App\Domain\Catalog\Product\Exceptions\MissingVariationSkuException;
use Webmozart\Assert\Assert;

/**
 * Product Variation Value Object.
 *
 * Represents a purchasable variant of a product (e.g., "Large, Red").
 * Contains pricing, stock, and option attributes.
 *
 * **External ID Instability**: Unlike parent products, variation external IDs
 * (`$id`) can change when product options are regenerated, variants are deleted
 * and recreated, or product structure is modified in ShopWired. Do NOT rely on
 * variation external_id for long-term tracking or analytics. Use SKU as the
 * stable identifier for variations.
 *
 * @see https://shopwired.readme.io/reference/getproduct
 */
final readonly class ProductVariation implements BasicProductInterface
{
    use BasicProductTrait;

    /** @var string Variation SKU (stable identifier - REQUIRED for all purchasable variants) */
    public string $sku;

    /**
     * @param int $id ShopWired variation ID (unstable - see class docblock)
     * @param int $productExternalId Parent product's ShopWired ID (for sync key)
     * @param string|null $sku Variation SKU - validated and assigned to property
     * @param float $price Selling price
     * @param float|null $costPrice Cost/wholesale price
     * @param float|null $salePrice Discounted price (null = no sale)
     * @param int $stock Stock quantity
     * @param float|null $weight Weight in configured unit (TODO: replace with Weight value object post-merge)
     * @param Gtin|null $gtin Global Trade Item Number (barcode)
     * @param string|null $mpn Manufacturer Part Number
     * @param string|null $imageUrl Variation-specific image URL
     * @param list<ProductVariationOption> $options Option attributes (e.g., Size, Color)
     *
     * @throws MissingVariationSkuException When SKU is null or empty
     */
    public function __construct(
        public int $id,
        public int $productExternalId,
        ?string $sku,
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

        $sku = $sku !== null ? \mb_trim($sku) : null;

        if ($sku === null || $sku === '') {
            throw new MissingVariationSkuException($id, $productExternalId);
        }

        $this->sku = $sku;
    }

    // BasicProductInterface implementation (isOnSale, effectivePrice provided by BasicProductTrait)

    public function sku(): string
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
