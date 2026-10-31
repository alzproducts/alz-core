<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Queries\ProductList;

use App\Domain\Catalog\Product\ValueObjects\ProductImage;
use DateTimeImmutable;

/**
 * One row of the list statement's `list_inputs` CTE: every input a product's list fragment is built from.
 *
 * A response field read from anywhere else would escape the fingerprint; the column-set unit test
 * keeps these properties equal to {@see ProductListSql::LIST_INPUT_COLUMNS}.
 *
 * @internal
 */
final readonly class ProductListInputRow
{
    public string $id;

    public int $externalId;

    public ?string $sku;

    public ?string $gtin;

    public string $title;

    public string $slug;

    public string $url;

    public float $price;

    public ?float $costPrice;

    public ?float $salePrice;

    public ?float $rrp;

    public float $effectivePrice;

    public bool $isOnSale;

    public bool $hasAnySale;

    public ?float $profitMargin;

    public bool $isActive;

    public bool $vatExclusive;

    public ?bool $vatRelief;

    public ?string $metaTitle;

    public ?string $metaDescription;

    /** @var list<int> */
    public array $categoryIds;

    /** @var list<int> */
    public array $mainCategoryIds;

    /** @var list<ProductImage> */
    public array $images;

    /** @var array<string, mixed> */
    public array $customFields;

    public ?int $sortOrder;

    public ?int $popularityRank;

    public ?int $popularityMax;

    public DateTimeImmutable $shopwiredCreatedAt;

    public DateTimeImmutable $shopwiredUpdatedAt;

    public int $availableStock;

    public int $physicalStock;

    public ?DateTimeImmutable $priceLastUpdatedAt;

    public ?DateTimeImmutable $costPriceLastUpdatedAt;

    public ?ProductListStockItemRow $stockItem;

    /** @var list<ProductListVariationRow> */
    public array $variations;

    /**
     * @param array<string, mixed> $row
     */
    public function __construct(array $row)
    {
        $this->id = ProductListValue::string($row, 'id');
        $this->externalId = ProductListValue::int($row, 'external_id');
        $this->sku = ProductListValue::nullableString($row, 'sku');
        $this->gtin = ProductListValue::nullableString($row, 'gtin');
        $this->title = ProductListValue::string($row, 'title');
        $this->slug = ProductListValue::string($row, 'slug');
        $this->url = ProductListValue::string($row, 'url');
        $this->price = ProductListValue::float($row, 'price');
        $this->costPrice = ProductListValue::nullableFloat($row, 'cost_price');
        $this->salePrice = ProductListValue::nullableFloat($row, 'sale_price');
        $this->rrp = ProductListValue::nullableFloat($row, 'rrp');
        $this->effectivePrice = ProductListValue::float($row, 'effective_price');
        $this->isOnSale = ProductListValue::bool($row, 'is_on_sale');
        $this->hasAnySale = ProductListValue::bool($row, 'has_any_sale');
        $this->profitMargin = ProductListValue::nullableFloat($row, 'profit_margin');
        $this->isActive = ProductListValue::bool($row, 'is_active');
        $this->vatExclusive = ProductListValue::bool($row, 'vat_exclusive');
        $this->vatRelief = ProductListValue::nullableBool($row, 'vat_relief');
        $this->metaTitle = ProductListValue::nullableString($row, 'meta_title');
        $this->metaDescription = ProductListValue::nullableString($row, 'meta_description');
        $this->categoryIds = ProductListValue::intList($row, 'category_ids');
        $this->mainCategoryIds = ProductListValue::intList($row, 'main_category_ids');
        $this->images = self::images(ProductListValue::objectList($row, 'images'));
        $this->customFields = ProductListValue::object($row, 'custom_fields');
        $this->sortOrder = ProductListValue::nullableInt($row, 'sort_order');
        $this->popularityRank = ProductListValue::nullableInt($row, 'popularity_rank');
        $this->popularityMax = ProductListValue::nullableInt($row, 'popularity_max');
        $this->shopwiredCreatedAt = ProductListValue::dateTime($row, 'shopwired_created_at');
        $this->shopwiredUpdatedAt = ProductListValue::dateTime($row, 'shopwired_updated_at');
        $this->availableStock = ProductListValue::int($row, 'available_stock');
        $this->physicalStock = ProductListValue::int($row, 'physical_stock');
        $this->priceLastUpdatedAt = ProductListValue::nullableDateTime($row, 'price_last_updated_at');
        $this->costPriceLastUpdatedAt = ProductListValue::nullableDateTime($row, 'cost_price_last_updated_at');
        $this->stockItem = self::stockItem(ProductListValue::nullableObject($row, 'stock_item'));
        $this->variations = \array_map(
            static fn(array $variation): ProductListVariationRow => new ProductListVariationRow($variation),
            ProductListValue::objectList($row, 'variations'),
        );
    }

    /**
     * @param list<array<string, mixed>> $images
     *
     * @return list<ProductImage>
     */
    private static function images(array $images): array
    {
        return \array_map(static fn(array $image): ProductImage => ProductImage::fromArray([
            'id' => ProductListValue::int($image, 'id'),
            'url' => ProductListValue::string($image, 'url'),
            'description' => ProductListValue::nullableString($image, 'description'),
            'sort_order' => ProductListValue::int($image, 'sort_order'),
        ]), $images);
    }

    /**
     * @param array<string, mixed>|null $stockItem
     */
    private static function stockItem(?array $stockItem): ?ProductListStockItemRow
    {
        return $stockItem !== null ? new ProductListStockItemRow($stockItem) : null;
    }
}
