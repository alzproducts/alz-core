<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Queries\ProductList;

use App\Domain\Catalog\Product\ValueObjects\Popularity;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationOption;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationView;
use DateTimeImmutable;

/**
 * One variation of a list product, as aggregated by {@see ProductListSql::VARIATION_FIELDS}.
 *
 * @internal
 */
final readonly class ProductListVariationRow
{
    public int $externalId;

    public ?string $sku;

    public ?string $gtin;

    public float $price;

    public ?float $costPrice;

    public ?float $salePrice;

    public ?float $rrp;

    public float $effectivePrice;

    public bool $isOnSale;

    public ?float $profitMargin;

    public int $availableStock;

    public int $physicalStock;

    public ?float $weight;

    public ?string $mpn;

    public ?int $imageIndex;

    /** @var list<ProductVariationOption> */
    public array $options;

    public DateTimeImmutable $createdAt;

    public DateTimeImmutable $updatedAt;

    public ?int $popularityRank;

    public ?int $popularityMax;

    public ?float $stockValue;

    public ?ProductListStockItemRow $stockItem;

    /**
     * @param array<string, mixed> $row
     */
    public function __construct(array $row)
    {
        $this->externalId = ProductListValue::int($row, 'external_id');
        $this->sku = ProductListValue::nullableString($row, 'sku');
        $this->gtin = ProductListValue::nullableString($row, 'gtin');
        $this->price = ProductListValue::float($row, 'price');
        $this->costPrice = ProductListValue::nullableFloat($row, 'cost_price');
        $this->salePrice = ProductListValue::nullableFloat($row, 'sale_price');
        $this->rrp = ProductListValue::nullableFloat($row, 'rrp');
        $this->effectivePrice = ProductListValue::float($row, 'effective_price');
        $this->isOnSale = ProductListValue::bool($row, 'is_on_sale');
        $this->profitMargin = ProductListValue::nullableFloat($row, 'profit_margin');
        $this->availableStock = ProductListValue::int($row, 'available_stock');
        $this->physicalStock = ProductListValue::int($row, 'physical_stock');
        $this->weight = ProductListValue::nullableFloat($row, 'weight');
        $this->mpn = ProductListValue::nullableString($row, 'mpn');
        $this->imageIndex = ProductListValue::nullableInt($row, 'image_index');
        $this->options = self::options(ProductListValue::objectList($row, 'options'));
        $this->createdAt = ProductListValue::dateTime($row, 'created_at');
        $this->updatedAt = ProductListValue::dateTime($row, 'updated_at');
        $this->popularityRank = ProductListValue::nullableInt($row, 'popularity_rank');
        $this->popularityMax = ProductListValue::nullableInt($row, 'popularity_max');
        $this->stockValue = ProductListValue::nullableFloat($row, 'stock_value');

        $stockItem = ProductListValue::nullableObject($row, 'stock_item');
        $this->stockItem = $stockItem !== null ? new ProductListStockItemRow($stockItem) : null;
    }

    public function toViewDomain(bool $vatExclusive): ProductVariationView
    {
        return new ProductVariationView(
            externalId: $this->externalId,
            sku: $this->sku,
            gtin: $this->gtin,
            price: $this->price,
            costPrice: $this->costPrice,
            salePrice: $this->salePrice,
            rrp: $this->rrp,
            effectivePrice: $this->effectivePrice,
            isOnSale: $this->isOnSale,
            profitMargin: $this->profitMargin,
            availableStock: $this->availableStock,
            physicalStock: $this->physicalStock,
            weight: $this->weight,
            vatExclusive: $vatExclusive,
            mpn: $this->mpn,
            imageIndex: $this->imageIndex,
            options: $this->options,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
            defaultSupplier: $this->stockItem?->defaultSupplier?->toProductSupplier(),
            suppliers: null,
            isComposite: $this->stockItem !== null && $this->stockItem->isComposite,
            inventory: $this->stockItem?->toProductInventory(),
            popularity: Popularity::fromRank($this->popularityRank, $this->popularityMax),
            stockValue: $this->stockValue,
        );
    }

    /**
     * @param list<array<string, mixed>> $options
     *
     * @return list<ProductVariationOption>
     */
    private static function options(array $options): array
    {
        return \array_map(static fn(array $option): ProductVariationOption => ProductVariationOption::fromArray([
            'option_id' => ProductListValue::int($option, 'option_id'),
            'option_name' => ProductListValue::string($option, 'option_name'),
            'value_id' => ProductListValue::int($option, 'value_id'),
            'value_name' => ProductListValue::string($option, 'value_name'),
        ]), $options);
    }
}
