<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Product\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
 * @property int|null $stock Master stock quantity
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
 * @property-read Collection<int, ProductVariationViewModel> $variations
 */
final class ProductViewModel extends Model
{
    protected $table = 'catalog.products_view';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
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
}
