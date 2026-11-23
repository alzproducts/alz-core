<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models\QuickBooks;

use App\Application\QuickBooks\OrderSync\DTOs\FailedOrderSyncDTO;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncRowStatus;
use App\Application\QuickBooks\OrderSync\Enums\SyncReasonCode;
use App\Domain\QuickBooks\OrderSync\Enums\DocType;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property int $reference
 * @property int $order_external_id
 * @property DocType|null $doc_type
 * @property OrderSyncRowStatus $status
 * @property int $attempts
 * @property CarbonImmutable|null $last_attempted_at
 * @property string|null $last_error_code
 * @property string|null $last_error_message
 * @property CarbonImmutable $updated_at
 */
final class FailedOrderSyncViewModel extends Model
{
    protected $table = 'quickbooks.order_syncs';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'reference' => 'integer',
            'order_external_id' => 'integer',
            'doc_type' => DocType::class,
            'status' => OrderSyncRowStatus::class,
            'attempts' => 'integer',
            'last_attempted_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function toDomain(?DateTimeImmutable $placedAt): FailedOrderSyncDTO
    {
        // intentional: a code this release does not know gets the one code that promises nothing about the post.
        $reason = SyncReasonCode::tryFrom($this->last_error_code ?? '') ?? SyncReasonCode::OutcomeUnknown;

        return new FailedOrderSyncDTO(
            reference: $this->reference,
            orderExternalId: $this->order_external_id,
            placedAt: $placedAt,
            docType: $this->doc_type,
            status: $this->status,
            attempts: $this->attempts,
            lastAttemptedAt: $this->last_attempted_at ?? $this->updated_at,
            reason: $reason,
            message: $this->last_error_message ?? $reason->message(),
        );
    }
}
