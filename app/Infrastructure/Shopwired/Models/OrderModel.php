<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Models;

use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Infrastructure\Contracts\EloquentDomainMappableInterface;
use App\Infrastructure\Shopwired\Mappers\OrderModelMapper;
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
 *
 * @property string $id Internal UUID
 * @property int $external_id ShopWired order ID
 * @property int $reference Customer-facing order number
 * @property float $total Order total
 * @property float $sub_total Subtotal before shipping
 * @property float $shipping_total Shipping cost
 * @property float $original_shipping_total Original shipping cost before discounts
 * @property float|null $tax_value Total tax value (null for VAT-exempt)
 * @property bool $line_item_vat_calculation Whether VAT is calculated per line item
 * @property int $status_id ShopWired status ID
 * @property int|null $status_sort_order Status display sort order
 * @property string $status_name Status display name (e.g., "Paid", "Dispatched")
 * @property string $status_type Status category (paid, unpaid, cancelled, shipped, custom)
 * @property string $lifecycle_status Derived lifecycle (pending, processing, shipped, completed, cancelled)
 * @property string $pre_order_status Pre-order status (none, partial, full)
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
 * @property int $billing_country_id ShopWired country ID for billing address
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
 * @property int $delivery_country_id ShopWired country ID for delivery address
 * @property int|null $shipping_id ShopWired shipping method ID
 * @property string|null $shipping_method Shipping method name
 * @property float $shipping_cost Shipping method cost (defaults to 0)
 * @property float|null $shipping_vat_rate
 * @property string|null $tracking_url Shipment tracking URL
 * @property string|null $invoice_url Invoice download URL
 * @property string $payment_method
 * @property string|null $transaction_id Payment transaction ID
 * @property bool $marketing Customer opted into marketing
 * @property bool $has_vat_relief VAT relief applied
 * @property bool $is_archived Whether order is archived in ShopWired
 * @property bool $is_anonymized Whether customer data has been anonymized (GDPR)
 * @property string|null $comments Order comments
 * @property array<string, mixed>|null $custom_fields Dynamic custom fields
 * @property CarbonImmutable $order_placed_at When order was placed in ShopWired
 * @property CarbonImmutable|null $delivery_date Expected/actual delivery date
 * @property CarbonImmutable $created_at When first synced to local DB
 * @property CarbonImmutable $updated_at When last updated locally
 *
 * @implements EloquentDomainMappableInterface<Order>
 */
final class OrderModel extends Model implements EloquentDomainMappableInterface
{
    use HasUuids;

    protected $table = 'shopwired.orders';

    protected $guarded = [];

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
            'original_shipping_total' => 'float',
            'tax_value' => 'float',
            'shipping_cost' => 'float',
            'shipping_vat_rate' => 'float',
            // JSON
            'customer_device_info' => 'array',
            'custom_fields' => 'array',
            // Booleans
            'line_item_vat_calculation' => 'boolean',
            'is_archived' => 'boolean',
            'is_anonymized' => 'boolean',
            // Timestamps
            'order_placed_at' => 'immutable_datetime',
            'delivery_date' => 'immutable_date',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
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

    /**
     * Get the order's refunds.
     *
     * @return HasMany<OrderRefundModel, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(OrderRefundModel::class, 'order_id', 'id');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Domain Mapping (via EloquentDomainMappableInterface)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Convert this model (with loaded relations) to Domain Order.
     */
    public function toDomain(): Order
    {
        return OrderModelMapper::fromModelWithRelations($this);
    }

    /**
     * Convert a Domain Order to model attributes.
     *
     * @param Order $entity
     *
     * @return array<string, mixed>
     */
    public static function fromDomainAttributes(object $entity): array
    {
        return OrderModelMapper::toModelAttributes($entity);
    }
}
