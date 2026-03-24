<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for shopwired.order_product_extra_data table.
 *
 * Stores manual data quality overrides for order products (e.g., SKU corrections).
 * Infrastructure-only metadata — does NOT map to a Domain object.
 *
 * @property string $id Internal UUID
 * @property int $order_external_id Parent order's ShopWired ID
 * @property int $external_id ShopWired product ID
 * @property string|null $variation_hash Matches order_products.variation_hash
 * @property string|null $sku_override Manual SKU correction
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class OrderProductExtraDataModel extends Model
{
    use HasUuids;

    protected $table = 'shopwired.order_product_extra_data';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
        return $this->belongsTo(OrderModel::class, 'order_external_id', 'external_id');
    }
}
