<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models\QuickBooks;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $realm_id
 * @property int $reference
 * @property int $order_external_id
 * @property string|null $doc_type
 * @property string $status
 * @property string|null $qbo_txn_id
 * @property string|null $qbo_doc_number
 * @property int $attempts
 * @property CarbonImmutable|null $last_attempted_at
 * @property string|null $last_error_code
 * @property string|null $last_error_message
 * @property string|null $claim_token
 * @property CarbonImmutable|null $lease_expires_at
 * @property string|null $payload_hash
 * @property CarbonImmutable|null $synced_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class OrderSyncModel extends Model
{
    use HasUuids;

    protected $table = 'quickbooks.order_syncs';

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'reference' => 'integer',
            'order_external_id' => 'integer',
            'attempts' => 'integer',
            'last_attempted_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
