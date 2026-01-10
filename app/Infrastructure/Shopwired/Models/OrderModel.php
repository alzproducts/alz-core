<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for shopwired.orders table.
 *
 * Stores ShopWired orders synced from the API. The `external_id` is ShopWired's
 * order ID, while `id` is our internal UUID (never exposed to Domain layer).
 *
 * Timestamps:
 * - order_placed_at: When customer placed order in ShopWired (business data)
 * - created_at/updated_at: Laravel-managed (when synced/updated locally)
 * - synced_at: Last successful API sync (set by repository)
 *
 * @property string $id Internal UUID
 * @property int $external_id ShopWired order ID
 * @property int $reference Customer-facing order number
 * @property float $total Order total
 * @property float $sub_total Subtotal before shipping
 * @property float $shipping_total Shipping cost
 * @property int $status_id ShopWired status ID
 * @property string $status_name Status display name (e.g., "Paid", "Dispatched")
 * @property string $status_type Status category (paid, unpaid, cancelled, shipped, custom)
 * @property string $lifecycle_status Derived lifecycle (pending, processing, shipped, completed, cancelled)
 * @property int $customer_id ShopWired customer ID
 * @property int $customer_type Customer type (0-3)
 * @property string|null $customer_date_of_birth
 * @property array<string, mixed>|null $customer_device_info Attribution data (IP, user agent, etc.)
 * @property string $billing_name
 * @property string $billing_email
 * @property string|null $billing_telephone
 * @property string|null $billing_company
 * @property string $billing_address_line1
 * @property string|null $billing_address_line2
 * @property string|null $billing_address_line3
 * @property string $billing_city
 * @property string|null $billing_province
 * @property string|null $billing_state
 * @property string $billing_postcode
 * @property string $billing_country
 * @property string $delivery_name
 * @property string $delivery_email
 * @property string|null $delivery_telephone
 * @property string|null $delivery_company
 * @property string $delivery_address_line1
 * @property string|null $delivery_address_line2
 * @property string|null $delivery_address_line3
 * @property string $delivery_city
 * @property string|null $delivery_province
 * @property string|null $delivery_state
 * @property string $delivery_postcode
 * @property string $delivery_country
 * @property string|null $shipping_method
 * @property float|null $shipping_cost Shipping method cost
 * @property float|null $shipping_vat_rate
 * @property string $payment_method
 * @property bool $marketing Customer opted into marketing
 * @property bool $has_vat_relief VAT relief applied
 * @property string|null $comments Order comments
 * @property array<string, mixed>|null $custom_fields Dynamic custom fields
 * @property CarbonImmutable $order_placed_at When order was placed in ShopWired
 * @property CarbonImmutable $created_at When first synced to local DB
 * @property CarbonImmutable $updated_at When last updated locally
 * @property CarbonImmutable $synced_at When last synced from API
 */
final class OrderModel extends Model
{
    use HasUuids;

    protected $table = 'shopwired.orders';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Money fields - cast to float to match Domain types
            'total' => 'float',
            'sub_total' => 'float',
            'shipping_total' => 'float',
            'shipping_cost' => 'float',
            'shipping_vat_rate' => 'float',
            // JSON
            'customer_device_info' => 'array',
            'custom_fields' => 'array',
            // Timestamps
            'order_placed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get the order's line items.
     *
     * @return HasMany<OrderProductModel, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(OrderProductModel::class, 'order_id', 'id');
    }

    /**
     * Get the order's discounts.
     *
     * @return HasMany<OrderDiscountModel, $this>
     */
    public function discounts(): HasMany
    {
        return $this->hasMany(OrderDiscountModel::class, 'order_id', 'id');
    }
}
