<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Models;

use App\Domain\Catalog\Order\ValueObjects\OrderProduct;
use App\Domain\Catalog\Order\ValueObjects\ProductVariation;
use App\Infrastructure\Contracts\EloquentDomainMappableInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for shopwired.order_products table.
 *
 * Stores order line items synced from ShopWired API.
 * Pricing fields preserve raw API values (up to 6dp) for accurate invoice generation.
 *
 * @property string $id Internal UUID
 * @property string $order_id Parent order UUID (FK relationship)
 * @property int $order_external_id Parent order's ShopWired ID (stable sync key)
 * @property int $external_id ShopWired product line ID
 * @property string $title Product title at time of purchase
 * @property string $sku Product SKU
 * @property float $price Unit price
 * @property float $price_vat VAT amount per unit
 * @property float $total Line total
 * @property float $total_vat Total VAT
 * @property float $original_price Original price before discounts
 * @property float|null $cost_price Cost price (nullable for older orders)
 * @property int $quantity Quantity ordered
 * @property float $vat_rate VAT rate percentage
 * @property string|null $comments Line item comments
 * @property array<int, array{name: string, value: string}>|null $variation Product variations
 * @property array<string, mixed>|null $custom_fields Dynamic custom fields
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 *
 * @implements EloquentDomainMappableInterface<OrderProduct>
 */
final class OrderProductModel extends Model implements EloquentDomainMappableInterface
{
    use HasUuids;

    protected $table = 'shopwired.order_products';

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
            'price' => 'float',
            'price_vat' => 'float',
            'total' => 'float',
            'total_vat' => 'float',
            'original_price' => 'float',
            'cost_price' => 'float',
            'vat_rate' => 'float',
            // JSON
            'variation' => 'array',
            'custom_fields' => 'array',
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
    // Domain Mapping (manual - has complex transformations)
    // ─────────────────────────────────────────────────────────────────────────

    public function toDomain(): OrderProduct
    {
        return new OrderProduct(
            id: $this->external_id,
            orderExternalId: $this->order_external_id,
            title: $this->title,
            sku: $this->sku,
            price: $this->price,
            priceVat: $this->price_vat,
            total: $this->total,
            totalVat: $this->total_vat,
            originalPrice: $this->original_price,
            costPrice: $this->cost_price,
            quantity: $this->quantity,
            vatRate: $this->vat_rate,
            comments: $this->comments ?? '',
            isPreorder: false, // TODO: Read from is_preorder column after migration #112
            preorderDate: null, // TODO: Read from preorder_date column after migration #112
            variation: $this->buildVariations(),
            customFields: $this->buildCustomFields(),
        );
    }

    /**
     * @param OrderProduct $entity
     *
     * @return array<string, mixed>
     */
    public static function fromDomainAttributes(object $entity): array
    {
        return [
            'order_external_id' => $entity->orderExternalId,
            'external_id' => $entity->id,
            'title' => $entity->title,
            'sku' => $entity->sku,
            'price' => $entity->price,
            'price_vat' => $entity->priceVat,
            'total' => $entity->total,
            'total_vat' => $entity->totalVat,
            'original_price' => $entity->originalPrice,
            'cost_price' => $entity->costPrice,
            'quantity' => $entity->quantity,
            'vat_rate' => $entity->vatRate,
            'comments' => $entity->comments,
            'variation' => \array_map(
                static fn(ProductVariation $v): array => $v->toArray(),
                $entity->variation,
            ),
            'custom_fields' => $entity->customFields,
        ];
    }

    /**
     * Convert DB variation arrays to ProductVariation objects.
     *
     * @return array<int, ProductVariation>
     */
    private function buildVariations(): array
    {
        if ($this->variation === null) {
            return [];
        }

        return \array_map(
            static fn(array $v): ProductVariation => ProductVariation::fromArray($v),
            $this->variation,
        );
    }

    /**
     * Convert DB custom fields (associative) to Domain format (indexed).
     *
     * DB stores: {fieldName: fieldValue, ...}
     * Domain expects: [{name: fieldName, value: fieldValue}, ...]
     *
     * @return list<array{name: string, value: string}>
     */
    private function buildCustomFields(): array
    {
        if ($this->custom_fields === null) {
            return [];
        }

        $result = [];
        foreach ($this->custom_fields as $name => $value) {
            $result[] = [
                'name' => $name,
                'value' => \is_scalar($value) ? (string) $value : '',
            ];
        }

        return $result;
    }
}
