<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use App\Domain\Inventory\ValueObjects\Weight;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\IntId;
use App\Domain\ValueObjects\TaxType;
use DateTimeImmutable;

/**
 * Read-only API projection of a product variation.
 *
 * Self-constructs domain types from primitives matching the SQL view's flat row shape.
 * The $vatExclusive param is a construction-only value from the parent product
 * used for TaxType derivation — not stored as a property.
 *
 * Constructed by ProductVariationViewModelMapper::toViewDomain() after price resolution.
 */
final readonly class ProductVariationView
{
    public IntId $id;

    public ?Sku $sku;

    public ?Gtin $gtin;

    public Money $price;

    public ?Money $costPrice;

    public ?Money $salePrice;

    public ?Money $rrp;

    public Money $effectivePrice;

    public ?Weight $weight;

    public bool $canEditCostPrice;

    public Stock $stockLevel;

    /**
     * @param int $externalId ShopWired variation ID
     * @param string|null $sku Variation SKU (nullable for legacy data)
     * @param string|null $gtin Global Trade Item Number
     * @param float $price Selling price (always resolved — never null)
     * @param float|null $costPrice Cost price from Linnworks (null = unknown)
     * @param float|null $salePrice Discounted price (null = no sale)
     * @param float|null $rrp RRP / "Was" price from per-SKU extra data
     * @param float $effectivePrice Selling price after sale logic
     * @param bool $isOnSale Whether this variation is currently on sale (from view)
     * @param float|null $profitMargin Retail profit margin % (from view, null when cost unknown)
     * @param int $availableStock Sellable stock (post order-book allocation)
     * @param int $physicalStock On-hand stock (pre order-book allocation)
     * @param float|null $weight Weight in kg
     * @param bool $vatExclusive Whether prices exclude VAT (from parent product, not stored)
     * @param string|null $mpn Manufacturer Part Number
     * @param int|null $imageIndex Index into parent product's images array
     * @param list<ProductVariationOption> $options Option attributes (e.g., Size, Color)
     * @param ProductSupplier|null $defaultSupplier Default supplier (null when no Linnworks stock item)
     * @param list<ProductSupplier>|null $suppliers All suppliers (null when not requested via include)
     * @param bool $isComposite Whether this variation's stock item is a composite parent
     * @param ProductInventory|null $inventory Linnworks inventory data (null when not requested via include)
     * @param Popularity|null $popularity SKU-level popularity from snapshot pipeline
     * @param DateTimeImmutable $createdAt Variation creation timestamp
     * @param DateTimeImmutable $updatedAt Variation last-update timestamp
     */
    public function __construct(
        int $externalId,
        ?string $sku,
        ?string $gtin,
        float $price,
        ?float $costPrice,
        ?float $salePrice,
        ?float $rrp,
        float $effectivePrice,
        public bool $isOnSale,
        public ?float $profitMargin,
        int $availableStock,
        int $physicalStock,
        ?float $weight,
        bool $vatExclusive,
        public ?string $mpn,
        public ?int $imageIndex,
        public array $options,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?ProductSupplier $defaultSupplier = null,
        public ?array $suppliers = null,
        public bool $isComposite = false,
        public ?ProductInventory $inventory = null,
        public ?Popularity $popularity = null,
    ) {
        $taxType = $vatExclusive ? TaxType::ZeroRated : TaxType::Inclusive;

        $this->id = IntId::from($externalId);
        $this->sku = $sku !== null && \mb_trim($sku) !== '' ? Sku::fromTrusted(\mb_trim($sku)) : null;
        $this->gtin = $gtin !== null ? Gtin::fromTrusted($gtin) : null;
        $this->price = Money::fromTaxType($price, $taxType);
        $this->costPrice = Money::nonZeroOrNull($costPrice, TaxType::Exclusive);
        $this->salePrice = Money::nonZeroOrNull($salePrice, $taxType);
        $this->rrp = Money::nonZeroOrNull($rrp, $taxType);
        $this->effectivePrice = Money::fromTaxType($effectivePrice, $taxType);
        $this->weight = $weight !== null ? Weight::kilogram($weight) : null;
        $this->canEditCostPrice = ! $isComposite && $defaultSupplier !== null;
        $this->stockLevel = new Stock($availableStock, $physicalStock);
    }

    /**
     * Return the common default supplier if all non-composite variations share the same one.
     *
     * Composite variations are excluded — they don't carry their own cost prices,
     * so including them would poison the supplier consistency check.
     *
     * Returns null when any non-composite variation lacks a default supplier,
     * when suppliers differ, or when all variations are composite.
     *
     * @param list<self> $variations Non-empty list
     */
    public static function commonDefaultSupplier(array $variations): ?ProductSupplier
    {
        $nonComposite = \array_values(\array_filter(
            $variations,
            static fn(self $v): bool => ! $v->isComposite,
        ));

        if ($nonComposite === [] || $nonComposite[0]->defaultSupplier === null) {
            return null;
        }

        $first = $nonComposite[0]->defaultSupplier;

        return \array_all(
            $nonComposite,
            static fn(self $v): bool => $v->defaultSupplier?->supplierName === $first->supplierName,
        ) ? $first : null;
    }

    /**
     * Check if any variation in the list is on sale.
     *
     * @param list<self>|null $variations
     */
    public static function anyOnSale(?array $variations): bool
    {
        if ($variations === null || $variations === []) {
            return false;
        }

        return \array_any(
            $variations,
            static fn(self $v): bool => $v->isOnSale,
        );
    }

    /**
     * Return the common cost price when every variation shares the same non-null cost.
     *
     * @param list<self> $variations
     */
    public static function commonCostPrice(array $variations): ?Money
    {
        return self::commonByField($variations, static fn(self $v): ?Money => $v->costPrice);
    }

    /**
     * Return the common selling price when every variation shares the same price.
     *
     * @param list<self> $variations
     */
    public static function commonPrice(array $variations): ?Money
    {
        return self::commonByField($variations, static fn(self $v): Money => $v->price);
    }

    /**
     * Return the common effective price when every variation shares the same effective price.
     *
     * @param list<self> $variations
     */
    public static function commonEffectivePrice(array $variations): ?Money
    {
        return self::commonByField($variations, static fn(self $v): Money => $v->effectivePrice);
    }

    /**
     * Return the lowest price across variations, compared by gross value for
     * a stable ordering regardless of tax type.
     *
     * @param list<self> $variations
     */
    public static function minPrice(array $variations): ?Money
    {
        return self::minByField($variations, static fn(self $v): Money => $v->price);
    }

    /**
     * Return the lowest effective price across variations, compared by gross value
     * for a stable ordering regardless of tax type.
     *
     * @param list<self> $variations
     */
    public static function minEffectivePrice(array $variations): ?Money
    {
        return self::minByField($variations, static fn(self $v): Money => $v->effectivePrice);
    }

    /**
     * Returns null when variations are empty, the extracted value on the first variation
     * is null, or any extracted value differs from the first.
     *
     * @param list<self> $variations
     * @param callable(self): ?Money $extractor
     */
    private static function commonByField(array $variations, callable $extractor): ?Money
    {
        if ($variations === []) {
            return null;
        }

        $first = $extractor($variations[0]);
        if ($first === null) {
            return null;
        }

        foreach ($variations as $variation) {
            $value = $extractor($variation);
            if ($value === null || ! $value->amountEquals($first)) {
                return null;
            }
        }

        return $first;
    }

    /**
     * @param list<self> $variations
     * @param callable(self): Money $extractor
     */
    private static function minByField(array $variations, callable $extractor): ?Money
    {
        if ($variations === []) {
            return null;
        }

        return \array_reduce(
            $variations,
            static function (Money $min, self $v) use ($extractor): Money {
                $value = $extractor($v);

                return $value->toGross() < $min->toGross() ? $value : $min;
            },
            $extractor($variations[0]),
        );
    }
}
