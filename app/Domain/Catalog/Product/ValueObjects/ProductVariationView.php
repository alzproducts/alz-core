<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use App\Domain\Inventory\ValueObjects\Weight;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\IntId;
use App\Domain\ValueObjects\TaxType;

/**
 * Read-only API projection of a product variation.
 *
 * Self-constructs domain types from primitives matching the SQL view's flat row shape.
 * The $vatExclusive param is a construction-only value from the parent product
 * used for TaxType derivation — not stored as a property.
 *
 * Constructed by ProductVariationModelMapper::toViewDomain() after price resolution.
 */
final readonly class ProductVariationView
{
    public IntId $id;

    public ?Sku $sku;

    public ?Gtin $gtin;

    public Money $price;

    public ?Money $costPrice;

    public ?Money $salePrice;

    public Money $effectivePrice;

    public ?Weight $weight;

    /**
     * @param int $externalId ShopWired variation ID
     * @param string|null $sku Variation SKU (nullable for legacy data)
     * @param string|null $gtin Global Trade Item Number
     * @param float $price Selling price (always resolved — never null)
     * @param float|null $costPrice Cost price from Linnworks (null = unknown)
     * @param float|null $salePrice Discounted price (null = no sale)
     * @param float $effectivePrice Selling price after sale logic
     * @param bool $isOnSale Whether this variation is currently on sale (from view)
     * @param float|null $profitMargin Retail profit margin % (from view, null when cost unknown)
     * @param int $stock Stock quantity
     * @param float|null $weight Weight in kg
     * @param bool $vatExclusive Whether prices exclude VAT (from parent product, not stored)
     * @param string|null $mpn Manufacturer Part Number
     * @param int|null $imageIndex Index into parent product's images array
     * @param list<ProductVariationOption> $options Option attributes (e.g., Size, Color)
     */
    public function __construct(
        int $externalId,
        ?string $sku,
        ?string $gtin,
        float $price,
        ?float $costPrice,
        ?float $salePrice,
        float $effectivePrice,
        public bool $isOnSale,
        public ?float $profitMargin,
        public int $stock,
        ?float $weight,
        bool $vatExclusive,
        public ?string $mpn,
        public ?int $imageIndex,
        public array $options,
    ) {
        $taxType = $vatExclusive ? TaxType::ZeroRated : TaxType::Inclusive;

        $this->id = IntId::from($externalId);
        $this->sku = $sku !== null && \mb_trim($sku) !== '' ? Sku::fromTrusted(\mb_trim($sku)) : null;
        $this->gtin = $gtin !== null ? Gtin::fromTrusted($gtin) : null;
        $this->price = Money::fromTaxType($price, $taxType);
        $this->costPrice = Money::nonZeroOrNull($costPrice, TaxType::Exclusive);
        $this->salePrice = Money::nonZeroOrNull($salePrice, $taxType);
        $this->effectivePrice = Money::fromTaxType($effectivePrice, $taxType);
        $this->weight = $weight !== null ? Weight::kilogram($weight) : null;
    }
}
