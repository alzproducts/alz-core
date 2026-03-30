<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for linnworks.purchase_orders table.
 *
 * Stores Linnworks purchase orders synced via the Phase 1 data layer.
 * The `linnworks_purchase_id` is Linnworks' GUID; `id` is our internal UUID.
 *
 * @property string $id Internal UUID
 * @property string $linnworks_purchase_id External Linnworks GUID (upsert key)
 * @property string $fk_supplier_id
 * @property string $fk_location_id
 * @property string $external_invoice_number
 * @property string $status
 * @property bool $locked
 * @property int $line_count
 * @property int $delivered_lines_count
 * @property string $currency
 * @property string $supplier_reference_number
 * @property int $unit_amount_tax_included_type
 * @property float $postage_paid
 * @property float $total_cost
 * @property float $tax_paid
 * @property float $shipping_tax_rate
 * @property float $conversion_rate
 * @property float $converted_shipping_cost
 * @property float $converted_shipping_tax
 * @property float $converted_other_cost
 * @property float $converted_other_tax
 * @property float $converted_grand_total
 * @property CarbonImmutable|null $date_of_purchase
 * @property CarbonImmutable|null $date_of_delivery
 * @property CarbonImmutable|null $quoted_delivery_date
 * @property int $note_count
 * @property CarbonImmutable $synced_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class PurchaseOrderModel extends Model
{
    use HasUuids;

    protected $table = 'linnworks.purchase_orders';

    /** Disable mass assignment protection (internal sync model, no user input). */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'locked' => 'boolean',
            'line_count' => 'integer',
            'delivered_lines_count' => 'integer',
            'unit_amount_tax_included_type' => 'integer',
            'postage_paid' => 'float',
            'total_cost' => 'float',
            'tax_paid' => 'float',
            'shipping_tax_rate' => 'float',
            'conversion_rate' => 'float',
            'converted_shipping_cost' => 'float',
            'converted_shipping_tax' => 'float',
            'converted_other_cost' => 'float',
            'converted_other_tax' => 'float',
            'converted_grand_total' => 'float',
            'date_of_purchase' => 'immutable_datetime',
            'date_of_delivery' => 'immutable_datetime',
            'quoted_delivery_date' => 'immutable_datetime',
            'note_count' => 'integer',
            'synced_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<PurchaseOrderItemModel, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(
            PurchaseOrderItemModel::class,
            'linnworks_purchase_id',
            'linnworks_purchase_id',
        );
    }

    /**
     * @return HasMany<PurchaseOrderAdditionalCostModel, $this>
     */
    public function additionalCosts(): HasMany
    {
        return $this->hasMany(
            PurchaseOrderAdditionalCostModel::class,
            'linnworks_purchase_id',
            'linnworks_purchase_id',
        );
    }

    /**
     * @return HasMany<PurchaseOrderDeliveredRecordModel, $this>
     */
    public function deliveredRecords(): HasMany
    {
        return $this->hasMany(
            PurchaseOrderDeliveredRecordModel::class,
            'linnworks_purchase_id',
            'linnworks_purchase_id',
        );
    }

    /**
     * @return HasMany<PurchaseOrderNoteModel, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(
            PurchaseOrderNoteModel::class,
            'linnworks_purchase_id',
            'linnworks_purchase_id',
        );
    }

    /**
     * @return HasMany<PurchaseOrderExtendedPropertyModel, $this>
     */
    public function extendedProperties(): HasMany
    {
        return $this->hasMany(
            PurchaseOrderExtendedPropertyModel::class,
            'linnworks_purchase_id',
            'linnworks_purchase_id',
        );
    }
}
