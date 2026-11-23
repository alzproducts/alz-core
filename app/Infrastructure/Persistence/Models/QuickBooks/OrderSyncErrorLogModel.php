<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models\QuickBooks;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Append-only: rows are inserted and never updated.
 *
 * @property int $id
 * @property string $order_sync_id
 * @property string $trigger
 * @property string|null $run_id
 * @property string $reason_code
 * @property string|null $qbo_fault_code
 * @property string|null $qbo_fault_element
 * @property string|null $message
 * @property CarbonImmutable $created_at
 */
final class OrderSyncErrorLogModel extends Model
{
    public const null UPDATED_AT = null;

    protected $table = 'quickbooks.order_sync_errors';

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }
}
