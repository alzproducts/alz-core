<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Models;

use App\Domain\Linnworks\ValueObjects\PurchaseOrderDeliveredRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for linnworks.purchase_order_delivered_records table.
 *
 * @property string $id Internal UUID
 * @property string $linnworks_purchase_id FK to purchase_orders.linnworks_purchase_id
 * @property int $linnworks_delivery_record_id Linnworks delivery record ID (upsert key)
 * @property string $fk_purchase_item_id
 * @property string $fk_stock_location_id
 * @property float $unit_cost
 * @property int $delivered_quantity
 * @property CarbonImmutable|null $created_date_time
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class PurchaseOrderDeliveredRecordModel extends Model
{
    use HasUuids;

    protected $table = 'linnworks.purchase_order_delivered_records';

    /** Disable mass assignment protection (internal sync model, no user input). */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'linnworks_delivery_record_id' => 'integer',
            'unit_cost' => 'float',
            'delivered_quantity' => 'integer',
            'created_date_time' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<PurchaseOrderModel, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(
            PurchaseOrderModel::class,
            'linnworks_purchase_id',
            'linnworks_purchase_id',
        );
    }

    /**
     * Convert domain PurchaseOrderDeliveredRecord to model attributes for bulk upsert.
     *
     * Note: Does NOT include 'linnworks_purchase_id' — that's set by the repository.
     * Includes timestamps because bulk upsert bypasses Eloquent's automatic handling.
     *
     * @return array<string, mixed>
     */
    public static function attributesFromDomain(PurchaseOrderDeliveredRecord $record): array
    {
        $now = CarbonImmutable::now();

        return [
            'linnworks_delivery_record_id' => $record->pkDeliveryRecordId->value,
            'fk_purchase_item_id' => $record->fkPurchaseItemId->value,
            'fk_stock_location_id' => $record->fkStockLocationId->value,
            'unit_cost' => $record->unitCost,
            'delivered_quantity' => $record->deliveredQuantity,
            'created_date_time' => $record->createdDateTime,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
