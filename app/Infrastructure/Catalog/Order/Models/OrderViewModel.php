<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Order\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only Eloquent model for catalog.orders_view.
 *
 * Backed by a PostgreSQL view built on `shopwired.orders_deduplicated` so
 * edited-order duplicates are filtered automatically. Lives in the catalog
 * schema (read-side concern) regardless of which sync source feeds it.
 *
 * Write operations continue to use OrderModel (shopwired.orders).
 *
 * @property string $id Internal UUID
 * @property int $external_id ShopWired order ID
 * @property int $reference Human-readable reference number
 * @property CarbonImmutable $placed_at Order placement timestamp
 * @property string $total Gross total (decimal string; preserves decimal(14,6) precision)
 * @property int $status_id ShopWired status ID
 * @property string $status_name Status name (enum input)
 * @property string $status_type Raw API type string
 * @property int|null $status_sort_order Status sort order
 * @property string $lifecycle_status Lifecycle status label
 * @property string $billing_email Billing email address
 * @property string $billing_name Billing full name
 * @property int|null $customer_id ShopWired customer ID (null for guest orders)
 */
final class OrderViewModel extends Model
{
    protected $table = 'catalog.orders_view';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'placed_at' => 'immutable_datetime',
            'total' => 'decimal:6',
            'status_id' => 'integer',
            'status_sort_order' => 'integer',
            'external_id' => 'integer',
            'reference' => 'integer',
            'customer_id' => 'integer',
        ];
    }
}
