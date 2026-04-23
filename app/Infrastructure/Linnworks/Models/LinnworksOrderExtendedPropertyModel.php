<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Models;

use App\Domain\Linnworks\ValueObjects\LinnworksOrderExtendedProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * Eloquent model for linnworks.order_extended_properties table.
 *
 * Stores extended property key-value pairs synced from the v2 GetOrders API.
 * Sync strategy: upsert by row_id + delete orphans.
 *
 * @property string $id Internal UUID
 * @property string $linnworks_order_id FK to orders.linnworks_order_id
 * @property string $row_id Linnworks EP GUID (upsert key)
 * @property string $name
 * @property string $value
 * @property string $type
 * @property CarbonImmutable|null $create_date
 * @property CarbonImmutable|null $last_update
 * @property string|null $updated_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class LinnworksOrderExtendedPropertyModel extends Model
{
    use HasUuids;

    protected $table = 'linnworks.order_extended_properties';

    /** Disable mass assignment protection (internal sync model, no user input). */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'create_date' => 'immutable_datetime',
            'last_update' => 'immutable_datetime',
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

    /**
     * Convert domain LinnworksOrderExtendedProperty to model attributes for bulk upsert.
     *
     * Note: Does NOT include 'linnworks_order_id' — that's set by the repository.
     * Includes timestamps because bulk upsert bypasses Eloquent's automatic handling.
     *
     * @return array<string, mixed>
     */
    public static function attributesFromDomain(LinnworksOrderExtendedProperty $ep): array
    {
        $now = CarbonImmutable::now();

        return [
            'row_id' => $ep->rowId->value,
            'name' => $ep->name,
            'value' => $ep->value,
            'type' => $ep->type,
            'create_date' => $ep->createDate,
            'last_update' => $ep->lastUpdate,
            'updated_by' => $ep->updatedBy,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
