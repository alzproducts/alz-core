<?php

declare(strict_types=1);

namespace App\Infrastructure\Ingest\Checkout\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Eloquent model for checkout.basket_snapshots table.
 *
 * Immutable insert-only snapshot of pre-checkout basket state. Used to fuzzy-match
 * completed orders that lost basket_comments data on Safari/Apple checkout.
 *
 * @property string $id UUID primary key
 * @property string $ip_address
 * @property string $user_agent
 * @property string $basket_total Decimal as string (cast preserves precision)
 * @property string|null $shipping_method_id
 * @property CarbonImmutable|null $delivery_date
 * @property string|null $gift_note
 * @property array<string, mixed>|null $vat_relief JSONB VAT-relief declaration
 * @property CarbonImmutable $created_at
 */
final class BasketSnapshotModel extends Model
{
    use HasUuids;

    protected $table = 'checkout.basket_snapshots';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Immutable records — no updated_at column.
     */
    public const UPDATED_AT = null;

    /**
     * No mass assignment protection needed — all writes are server-controlled
     * via repository with explicit attribute assignment.
     */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'vat_relief' => 'array',
            'delivery_date' => 'immutable_date',
            'basket_total' => 'decimal:2',
            'created_at' => 'immutable_datetime',
        ];
    }
}
