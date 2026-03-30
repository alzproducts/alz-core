<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Models;

use App\Domain\Linnworks\ValueObjects\PurchaseOrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for linnworks.purchase_order_items table.
 *
 * Stores purchase order line items from the Get_PurchaseOrder API response.
 *
 * @property string $id Internal UUID
 * @property string $linnworks_purchase_id FK to purchase_orders.linnworks_purchase_id
 * @property string $linnworks_purchase_item_id Linnworks item GUID (upsert key)
 * @property string $fk_stock_item_id
 * @property int|null $stock_item_int_id
 * @property int $quantity
 * @property int $delivered
 * @property int $pack_quantity
 * @property int $pack_size
 * @property float $cost
 * @property float $tax
 * @property float $tax_rate
 * @property string $sku
 * @property string $item_title
 * @property string $barcode_number
 * @property string $supplier_code
 * @property string $supplier_barcode
 * @property float $dim_height
 * @property float $dim_width
 * @property float $dim_depth
 * @property bool $is_deleted
 * @property int $inventory_tracking_type
 * @property int $sort_order
 * @property string $bin_rack
 * @property int $bound_to_open_orders_items
 * @property int $quantity_bound_to_open_orders_items
 * @property array<int, mixed>|null $sku_group_ids
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class PurchaseOrderItemModel extends Model
{
    use HasUuids;

    protected $table = 'linnworks.purchase_order_items';

    /** Disable mass assignment protection (internal sync model, no user input). */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stock_item_int_id' => 'integer',
            'quantity' => 'integer',
            'delivered' => 'integer',
            'pack_quantity' => 'integer',
            'pack_size' => 'integer',
            'cost' => 'float',
            'tax' => 'float',
            'tax_rate' => 'float',
            'dim_height' => 'float',
            'dim_width' => 'float',
            'dim_depth' => 'float',
            'is_deleted' => 'boolean',
            'inventory_tracking_type' => 'integer',
            'sort_order' => 'integer',
            'bound_to_open_orders_items' => 'integer',
            'quantity_bound_to_open_orders_items' => 'integer',
            'sku_group_ids' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<PurchaseOrderModel, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(
            PurchaseOrderModel::class,
            'linnworks_purchase_id',
            'linnworks_purchase_id',
        );
    }

    /**
     * Convert domain PurchaseOrderItem to model attributes for bulk upsert.
     *
     * Note: Does NOT include 'linnworks_purchase_id' — that's set by the repository.
     * Includes timestamps because bulk upsert bypasses Eloquent's automatic handling.
     *
     * @return array<string, mixed>
     */
    public static function attributesFromDomain(PurchaseOrderItem $item): array
    {
        $now = CarbonImmutable::now();

        return [
            'linnworks_purchase_item_id' => $item->pkPurchaseItemId->value,
            'fk_stock_item_id' => $item->fkStockItemId->value,
            'stock_item_int_id' => $item->stockItemIntId?->value,
            'quantity' => $item->quantity,
            'delivered' => $item->delivered,
            'pack_quantity' => $item->packQuantity,
            'pack_size' => $item->packSize,
            'cost' => $item->cost,
            'tax' => $item->tax,
            'tax_rate' => $item->taxRate->percentage,
            'sku' => $item->sku->value,
            'item_title' => $item->itemTitle,
            'barcode_number' => $item->barcodeNumber,
            'supplier_code' => $item->supplierCode,
            'supplier_barcode' => $item->supplierBarcode,
            'dim_height' => $item->dimHeight,
            'dim_width' => $item->dimWidth,
            'dim_depth' => $item->dimDepth,
            'is_deleted' => $item->isDeleted,
            'inventory_tracking_type' => $item->inventoryTrackingType,
            'sort_order' => $item->sortOrder,
            'bin_rack' => $item->binRack,
            'bound_to_open_orders_items' => $item->boundToOpenOrdersItems,
            'quantity_bound_to_open_orders_items' => $item->quantityBoundToOpenOrdersItems,
            'sku_group_ids' => $item->skuGroupIds !== [] ? $item->skuGroupIds : null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
