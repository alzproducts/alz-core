<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Product\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Override;

/**
 * Eloquent model for shopwired.product_variations table.
 *
 * Stores product variations synced from ShopWired API.
 * Uses composite unique key (product_external_id, external_id) for stable sync.
 *
 * **Domain Conversion**: Use ProductVariationModelMapper for all model ↔ domain conversion.
 *
 * @property string $id Internal UUID
 * @property string $product_id Parent product UUID (FK relationship)
 * @property int $product_external_id Parent product's ShopWired ID (stable sync key)
 * @property int $external_id ShopWired variation ID
 * @property string|null $sku Variation SKU
 * @property float|null $price Selling price (null = inherit parent, 0.00 = removed from sale)
 * @property float|null $cost_price Cost/wholesale price (-1.0 = inherit parent, 0.00 = unknown, >0 = valid)
 * @property float|null $sale_price Discounted price
 * @property int $stock Stock quantity
 * @property float|null $weight Weight in configured unit
 * @property string|null $gtin Global Trade Item Number (barcode)
 * @property string|null $mpn Manufacturer Part Number
 * @property int|null $image_index Index into parent product's images array
 * @property list<array{option_id: int, option_name: string, value_id: int, value_name: string}> $options Option attributes
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class ProductVariationModel extends Model
{
    use HasUuids;

    protected $table = 'shopwired.product_variations';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            // Money fields - cast to float to match Domain types
            'price' => 'float',
            'cost_price' => 'float',
            'sale_price' => 'float',
            'weight' => 'float',
            // JSON
            'options' => 'array',
            // Timestamps
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get the parent product.
     *
     * @return BelongsTo<ProductModel, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class, 'product_id', 'id');
    }

    /**
     * Get the variation's extra data (RRP, etc.) matched by SKU.
     *
     * @return HasOne<ProductExtraDataModel, $this>
     */
    public function extraData(): HasOne
    {
        return $this->hasOne(ProductExtraDataModel::class, 'sku', 'sku');
    }
}
