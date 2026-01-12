<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Models;

use App\Domain\Catalog\Order\ValueObjects\OrderAdminComment;
use App\Infrastructure\Concerns\AutoDomainMappingTrait;
use App\Infrastructure\Contracts\EloquentDomainMappableInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for shopwired.order_admin_comments table.
 *
 * Stores admin notes on orders, synced from ShopWired API.
 * Sync strategy is "replace all" - no stable ID for upserts.
 *
 * @property string $id Internal UUID
 * @property string $order_id Parent order UUID
 * @property int $order_external_id Parent order's ShopWired ID
 * @property int|null $external_id ShopWired comment ID (for debugging)
 * @property string $content Comment text
 * @property int|null $status_id Associated ShopWired status ID
 * @property CarbonImmutable|null $created_at_shopwired When comment was created in ShopWired
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 *
 * @implements EloquentDomainMappableInterface<OrderAdminComment>
 */
final class OrderAdminCommentModel extends Model implements EloquentDomainMappableInterface
{
    use AutoDomainMappingTrait;
    use HasUuids;

    protected $table = 'shopwired.order_admin_comments';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'external_id' => 'integer',
            'order_external_id' => 'integer',
            'status_id' => 'integer',
            'created_at_shopwired' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get the parent order.
     *
     * @return BelongsTo<OrderModel, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(OrderModel::class, 'order_id', 'id');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Domain Mapping
    // ─────────────────────────────────────────────────────────────────────────

    protected function domainClass(): string
    {
        return OrderAdminComment::class;
    }
}
