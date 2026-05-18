<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Product\Models;

use App\Infrastructure\Concerns\QueriesJsonbColumnsTrait;
use App\Infrastructure\Linnworks\Models\StockItemModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Override;

/**
 * Read-only Eloquent model for catalog.products_view.
 *
 * Backed by a PostgreSQL view that joins shopwired.products with Linnworks
 * cost prices and pre-computes derived columns (effective_price, is_on_sale,
 * profit_margin). Used exclusively for the read path (API list/show endpoints).
 *
 * Write operations continue to use ProductModel (shopwired.products).
 *
 * @property string $id Internal UUID
 * @property int $external_id ShopWired product ID
 * @property string|null $sku Master SKU
 * @property string|null $gtin Global Trade Item Number
 * @property string $title Product title
 * @property string|null $description HTML description
 * @property string $slug URL slug
 * @property string $url Full product URL
 * @property float $price Selling price
 * @property float|null $sale_price Discounted price
 * @property float|null $compare_price RRP / "Was" price
 * @property int|null $stock Master stock quantity (legacy — prefer available_stock/physical_stock)
 * @property int $available_stock Sellable stock (Linnworks `available` column, COALESCEd to `stock`)
 * @property int $physical_stock On-hand stock (Linnworks `quantity` column, COALESCEd to `stock`)
 * @property bool $is_active Published/visible
 * @property bool $vat_exclusive Price excludes VAT
 * @property bool|null $vat_relief VAT relief eligible
 * @property float|null $weight Weight in configured unit
 * @property int|null $sort_order ShopWired sort order
 * @property string|null $meta_title SEO title
 * @property string|null $meta_description SEO description
 * @property list<int> $category_ids ShopWired category IDs
 * @property list<array{id: int, url: string, description: string|null, sort_order: int}> $images Product images
 * @property array<string, mixed> $custom_fields Raw custom fields from API
 * @property array<int|string, list<string>> $filters Raw filter data from API
 * @property CarbonImmutable $shopwired_created_at ShopWired creation timestamp
 * @property CarbonImmutable $shopwired_updated_at ShopWired last update timestamp
 * @property CarbonImmutable $created_at When first synced to local DB
 * @property CarbonImmutable $updated_at When last updated locally
 * @property bool $is_on_sale Whether effective price is a sale price
 * @property float $effective_price Selling price after sale logic (sale_price if active, else price)
 * @property float|null $cost_price Linnworks cost price (from default supplier, tax-exclusive)
 * @property float|null $profit_margin Gross profit margin % computed at DB level
 * @property bool $has_free_delivery Whether product has a free delivery designation
 * @property list<int> $main_category_ids Main category IDs this product belongs to (directly or via ancestor chain)
 * @property int|null $popularity_rank Latest snapshot's calculated_sort_order (1 = most popular)
 * @property int|null $popularity_max Max_rank from the config active at snapshot time
 * @property float|null $profit_margin_min COALESCE(parent profit_margin, MIN(variation profit_margin))
 * @property float|null $profit_margin_max COALESCE(parent profit_margin, MAX(variation profit_margin))
 * @property float|null $net_margin_single_unit_min COALESCE(parent net_margin, MIN(variation net_margin_single_unit))
 * @property float|null $net_margin_single_unit_max COALESCE(parent net_margin, MAX(variation net_margin_single_unit))
 * @property-read Collection<int, ProductVariationViewModel> $variations
 * @property-read StockItemModel|null $stockItem
 */
final class ProductViewModel extends Model
{
    use QueriesJsonbColumnsTrait;

    protected $table = 'catalog.products_view';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            ...self::numericCasts(),
            ...self::jsonCasts(),
            ...self::boolCasts(),
            ...self::timestampCasts(),
        ];
    }

    /** @return array<string, string> */
    private static function numericCasts(): array
    {
        return [
            'price' => 'float',
            'sale_price' => 'float',
            'compare_price' => 'float',
            'weight' => 'float',
            'effective_price' => 'float',
            'cost_price' => 'float',
            'profit_margin' => 'float',
            'available_stock' => 'integer',
            'physical_stock' => 'integer',
            'popularity_rank' => 'integer',
            'popularity_max' => 'integer',
            'profit_margin_min' => 'float',
            'profit_margin_max' => 'float',
            'net_margin_single_unit_min' => 'float',
            'net_margin_single_unit_max' => 'float',
        ];
    }

    /** @return array<string, string> */
    private static function jsonCasts(): array
    {
        return [
            'category_ids' => 'array',
            'images' => 'array',
            'custom_fields' => 'array',
            'filters' => 'array',
            'main_category_ids' => 'array',
        ];
    }

    /** @return array<string, string> */
    private static function boolCasts(): array
    {
        return [
            'is_active' => 'boolean',
            'vat_exclusive' => 'boolean',
            'vat_relief' => 'boolean',
            'is_on_sale' => 'boolean',
            'has_free_delivery' => 'boolean',
        ];
    }

    /** @return array<string, string> */
    private static function timestampCasts(): array
    {
        return [
            'shopwired_created_at' => 'immutable_datetime',
            'shopwired_updated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get the product's variations from the read-model view.
     *
     * @return HasMany<ProductVariationViewModel, $this>
     */
    public function variations(): HasMany
    {
        return $this->hasMany(ProductVariationViewModel::class, 'product_id', 'id');
    }

    /**
     * Get the product's extra data (RRP, etc.) matched by SKU.
     *
     * Cross-schema relation: catalog.products_view.sku → catalog.product_extra_data.sku.
     *
     * @return HasOne<ProductExtraDataModel, $this>
     */
    public function extraData(): HasOne
    {
        return $this->hasOne(ProductExtraDataModel::class, 'sku', 'sku');
    }

    /**
     * Get the Linnworks stock item matched by SKU.
     *
     * Cross-schema relation: catalog.products_view.sku → linnworks.stock_items.item_number.
     *
     * @return HasOne<StockItemModel, $this>
     */
    public function stockItem(): HasOne
    {
        return $this->hasOne(StockItemModel::class, 'item_number', 'sku');
    }
}
