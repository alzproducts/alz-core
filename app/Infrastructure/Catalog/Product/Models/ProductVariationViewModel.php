<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Product\Models;

use App\Infrastructure\Linnworks\Models\StockItemModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Override;

/**
 * Read-only Eloquent model for catalog.product_variations_view.
 *
 * Backed by a PostgreSQL view that resolves price inheritance from the parent
 * product via COALESCE, joins Linnworks cost prices, and pre-computes derived
 * columns. Used exclusively for the read path.
 *
 * Write operations continue to use ProductVariationModel (shopwired.product_variations).
 *
 * @property string $id Internal UUID
 * @property string $product_id Parent product UUID (FK)
 * @property int $product_external_id Parent product's ShopWired ID
 * @property int $external_id ShopWired variation ID
 * @property string|null $sku Variation SKU
 * @property int $stock Stock quantity
 * @property float|null $weight Weight in configured unit
 * @property string|null $gtin Global Trade Item Number
 * @property string|null $mpn Manufacturer Part Number
 * @property int|null $image_index Index into parent product's images array
 * @property list<array{option_id: int, option_name: string, value_id: int, value_name: string}> $options Option attributes
 * @property int $available_stock Sellable stock (Linnworks `available` column, COALESCEd to `stock`)
 * @property int $physical_stock On-hand stock (Linnworks `quantity` column, COALESCEd to `stock`)
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property float|null $raw_price Variation's own price before parent inheritance
 * @property float|null $raw_sale_price Variation's own sale price before parent inheritance
 * @property float $price Resolved price (variation's own or inherited from parent via COALESCE)
 * @property float|null $sale_price Resolved sale price (variation's own or inherited from parent)
 * @property bool $is_on_sale Whether effective price is a sale price
 * @property float $effective_price Selling price after sale logic
 * @property float|null $cost_price Linnworks cost price (by variation SKU, tax-exclusive)
 * @property float|null $profit_margin Gross profit margin % computed at DB level
 * @property int|null $popularity_rank Popularity rank from SKU snapshot pipeline (calculated_sort_order)
 * @property int|null $popularity_max Max rank from active SKU popularity config (max_rank)
 */
final class ProductVariationViewModel extends Model
{
    protected $table = 'catalog.product_variations_view';

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
            'price' => 'float',
            'sale_price' => 'float',
            'weight' => 'float',
            'raw_price' => 'float',
            'raw_sale_price' => 'float',
            'effective_price' => 'float',
            'cost_price' => 'float',
            'profit_margin' => 'float',
            'popularity_rank' => 'integer',
            'popularity_max' => 'integer',
            'is_on_sale' => 'boolean',
            'available_stock' => 'integer',
            'physical_stock' => 'integer',
            'options' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get the variation's extra data (RRP, etc.) matched by SKU.
     *
     * @return HasOne<ProductExtraDataModel, $this>
     */
    public function extraData(): HasOne
    {
        return $this->hasOne(ProductExtraDataModel::class, 'sku', 'sku');
    }

    /**
     * Get the Linnworks stock item for this variation's SKU.
     *
     * Cross-schema relation: catalog.product_variations_view.sku → linnworks.stock_items.item_number.
     *
     * @return HasOne<StockItemModel, $this>
     */
    public function stockItem(): HasOne
    {
        return $this->hasOne(StockItemModel::class, 'item_number', 'sku');
    }
}
