<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Models;

use App\Domain\Linnworks\ValueObjects\PurchaseOrderNote;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for linnworks.purchase_order_notes table.
 *
 * @property string $id Internal UUID
 * @property string $linnworks_purchase_id FK to purchase_orders.linnworks_purchase_id
 * @property string $linnworks_purchase_order_note_id Linnworks note GUID (upsert key)
 * @property string $note
 * @property CarbonImmutable|null $date_time
 * @property string|null $user_name
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class PurchaseOrderNoteModel extends Model
{
    use HasUuids;

    protected $table = 'linnworks.purchase_order_notes';

    /** Disable mass assignment protection (internal sync model, no user input). */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_time' => 'immutable_datetime',
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
     * Convert domain PurchaseOrderNote to model attributes for bulk upsert.
     *
     * Note: Does NOT include 'linnworks_purchase_id' — that's set by the repository.
     * Includes timestamps because bulk upsert bypasses Eloquent's automatic handling.
     *
     * @return array<string, mixed>
     */
    public static function attributesFromDomain(PurchaseOrderNote $note): array
    {
        $now = CarbonImmutable::now();

        return [
            'linnworks_purchase_order_note_id' => $note->pkPurchaseOrderNoteId->value,
            'note' => $note->note,
            'date_time' => $note->dateTime,
            'user_name' => $note->userName,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
