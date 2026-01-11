<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for shopwired.order_products table.
 *
 * Stores order line items synced from ShopWired API.
 * Pricing fields preserve raw API values (up to 6dp) for accurate invoice generation.
 *
 * @property string $id Internal UUID
 * @property string $order_id Parent order UUID
 * @property int $external_id ShopWired product instance ID (globally unique)
 * @property string $title Product title at time of purchase
 * @property string $sku Product SKU
 * @property float $price Unit price
 * @property float $price_vat VAT amount per unit
 * @property float $total Line total
 * @property float $total_vat Total VAT
 * @property float $original_price Original price before discounts
 * @property float $cost_price Cost price
 * @property int $quantity Quantity ordered
 * @property float $vat_rate VAT rate percentage
 * @property string|null $comments Line item comments
 * @property array<int, array{name: string, value: string}>|null $variation Product variations
 * @property array<string, mixed>|null $custom_fields Dynamic custom fields
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class OrderProductModel extends Model
{
    use HasUuids;

    protected $table = 'shopwired.order_products';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Money fields - cast to float to match Domain types
            'price' => 'float',
            'price_vat' => 'float',
            'total' => 'float',
            'total_vat' => 'float',
            'original_price' => 'float',
            'cost_price' => 'float',
            'vat_rate' => 'float',
            // JSON
            'variation' => 'array',
            'custom_fields' => 'array',
            // Timestamps
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get the parent order.
     *
     * @return BelongsTo<OrderModel, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(OrderModel::class, 'order_id', 'id');
    }
}
