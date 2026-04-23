<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Models;

use App\Domain\Catalog\Order\ValueObjects\OrderDiscount;
use App\Infrastructure\Concerns\AutoDomainMappingTrait;
use App\Infrastructure\Contracts\EloquentDomainMappableInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * Eloquent model for shopwired.order_discounts table.
 *
 * Stores discounts applied to orders, synced from ShopWired API.
 *
 * @property string $id Internal UUID
 * @property string $order_id Parent order UUID
 * @property int $order_external_id Parent order's ShopWired ID
 * @property string $name Discount name/description
 * @property float $value Discount amount
 * @property string|null $type Discount type
 * @property string|null $code Promo/voucher code used
 * @property int|null $voucher_id ShopWired voucher ID (for tracking)
 * @property int|null $offer_id ShopWired offer ID (for tracking)
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 *
 * @implements EloquentDomainMappableInterface<OrderDiscount>
 */
final class OrderDiscountModel extends Model implements EloquentDomainMappableInterface
{
    use AutoDomainMappingTrait;
    use HasUuids;

    protected $table = 'shopwired.order_discounts';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            // Money field
            'value' => 'float',
            // Timestamps
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
        return OrderDiscount::class;
    }
}
