<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models\QuickBooks;

use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncStateDTO;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncRowStatus;
use App\Domain\QuickBooks\OrderSync\Enums\DocType;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property int $reference
 * @property OrderSyncRowStatus $status
 * @property bool $lease_live computed by the query, on the database clock
 * @property DocType|null $doc_type
 * @property string|null $qbo_doc_number
 * @property string|null $qbo_txn_id
 */
final class OrderSyncStateViewModel extends Model
{
    protected $table = 'quickbooks.order_syncs';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'reference';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'reference' => 'integer',
            'status' => OrderSyncRowStatus::class,
            'lease_live' => 'boolean',
            'doc_type' => DocType::class,
        ];
    }

    public function toDomain(): OrderSyncStateDTO
    {
        return new OrderSyncStateDTO(
            status: $this->status,
            leaseLive: $this->lease_live,
            docType: $this->doc_type,
            qboDocNumber: $this->qbo_doc_number,
            qboTxnId: $this->qbo_txn_id,
        );
    }
}
