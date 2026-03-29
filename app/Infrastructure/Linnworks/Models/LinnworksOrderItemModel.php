<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for linnworks.order_items table.
 *
 * Stores line items synced from the v2 GetOrders API. Composite sub-items
 * are flattened with a non-null `parent_item_id` referencing the composite parent.
 *
 * @property string $id Internal UUID
 * @property string $linnworks_order_id FK to orders.linnworks_order_id
 * @property string $row_id Linnworks item GUID (upsert key)
 * @property string|null $parent_item_id Composite parent RowId
 * @property string $stock_item_id
 * @property int|null $stock_item_int_id
 * @property string $item_number
 * @property string $sku
 * @property string $item_source
 * @property string $title
 * @property string $category_id
 * @property string|null $category_name
 * @property int $quantity
 * @property float $price_per_unit
 * @property float $unit_cost
 * @property float $despatch_stock_unit_cost
 * @property float $discount
 * @property float $tax_rate
 * @property float $cost
 * @property float $cost_inc_tax
 * @property float $sales_tax
 * @property bool $tax_cost_inclusive
 * @property float $discount_value
 * @property float $weight
 * @property string|null $barcode_number
 * @property string $channel_sku
 * @property string $channel_title
 * @property bool $batch_number_scan_required
 * @property bool $serial_number_scan_required
 * @property bool $is_service
 * @property bool $is_unlinked
 * @property CarbonImmutable $added_date
 * @property array<int, mixed>|null $additional_info
 * @property array<int, mixed>|null $bin_racks
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class LinnworksOrderItemModel extends Model
{
    use HasUuids;

    protected $table = 'linnworks.order_items';

    /** Disable mass assignment protection (internal sync model, no user input). */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stock_item_int_id' => 'integer',
            'quantity' => 'integer',
            'price_per_unit' => 'float',
            'unit_cost' => 'float',
            'despatch_stock_unit_cost' => 'float',
            'discount' => 'float',
            'tax_rate' => 'float',
            'cost' => 'float',
            'cost_inc_tax' => 'float',
            'sales_tax' => 'float',
            'tax_cost_inclusive' => 'boolean',
            'discount_value' => 'float',
            'weight' => 'float',
            'batch_number_scan_required' => 'boolean',
            'serial_number_scan_required' => 'boolean',
            'is_service' => 'boolean',
            'is_unlinked' => 'boolean',
            'added_date' => 'immutable_datetime',
            'additional_info' => 'array',
            'bin_racks' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<LinnworksOrderModel, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(
            LinnworksOrderModel::class,
            'linnworks_order_id',
            'linnworks_order_id',
        );
    }
}
