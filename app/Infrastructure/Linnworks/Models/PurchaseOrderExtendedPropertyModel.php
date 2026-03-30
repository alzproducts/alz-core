<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Models;

use App\Domain\Linnworks\ValueObjects\PurchaseOrderExtendedProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for linnworks.purchase_order_extended_properties table.
 *
 * @property string $id Internal UUID
 * @property string $linnworks_purchase_id FK to purchase_orders.linnworks_purchase_id
 * @property int|null $row_id Linnworks row ID (upsert key)
 * @property string $property_name
 * @property string $property_value
 * @property string|null $added_date_time
 * @property string|null $username
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class PurchaseOrderExtendedPropertyModel extends Model
{
    use HasUuids;

    protected $table = 'linnworks.purchase_order_extended_properties';

    /** Disable mass assignment protection (internal sync model, no user input). */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'row_id' => 'integer',
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
     * Convert domain PurchaseOrderExtendedProperty to model attributes for bulk upsert.
     *
     * Note: Does NOT include 'linnworks_purchase_id' — that's set by the repository.
     * Includes timestamps because bulk upsert bypasses Eloquent's automatic handling.
     *
     * @return array<string, mixed>
     */
    public static function attributesFromDomain(PurchaseOrderExtendedProperty $ep): array
    {
        $now = CarbonImmutable::now();

        return [
            'row_id' => $ep->rowId,
            'property_name' => $ep->propertyName,
            'property_value' => $ep->propertyValue,
            'added_date_time' => $ep->addedDateTime,
            'username' => $ep->username,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
