<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Models;

use App\Domain\Inventory\ValueObjects\StockItemSupplier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for linnworks.stock_item_suppliers table.
 *
 * Stores supplier relationships for stock items. Sync strategy:
 * delete all suppliers for item → re-insert fresh from API.
 *
 * @property string $id Internal UUID
 * @property string $stock_item_id FK to stock_items.stock_item_id
 * @property string $supplier_id Linnworks supplier GUID
 * @property string $supplier_name
 * @property string|null $code
 * @property string|null $supplier_barcode
 * @property float|null $purchase_price
 * @property bool $is_default
 * @property int|null $lead_time
 * @property string|null $supplier_currency
 * @property float|null $min_price
 * @property float|null $max_price
 * @property float|null $average_price
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class StockItemSupplierModel extends Model
{
    use HasUuids;

    protected $table = 'linnworks.stock_item_suppliers';

    /** Disable mass assignment protection (internal sync model, no user input). */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purchase_price' => 'float',
            'is_default' => 'boolean',
            'lead_time' => 'integer',
            'min_price' => 'float',
            'max_price' => 'float',
            'average_price' => 'float',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<StockItemModel, $this>
     */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(
            StockItemModel::class,
            'stock_item_id',
            'stock_item_id',
        );
    }

    /**
     * Convert Eloquent model to domain StockItemSupplier.
     */
    public function toDomain(): StockItemSupplier
    {
        return new StockItemSupplier(
            supplierId: $this->supplier_id,
            supplierName: $this->supplier_name,
            code: $this->code,
            supplierBarcode: $this->supplier_barcode,
            purchasePrice: $this->purchase_price,
            isDefault: $this->is_default,
            leadTime: $this->lead_time,
            supplierCurrency: $this->supplier_currency,
            minPrice: $this->min_price,
            maxPrice: $this->max_price,
            averagePrice: $this->average_price,
        );
    }

    /**
     * Convert domain StockItemSupplier to model attributes for bulk insert.
     *
     * Note: Does NOT include 'stock_item_id' - that's set by the repository.
     * Includes timestamps because bulk insert() bypasses Eloquent's automatic handling.
     *
     * @return array<string, mixed>
     */
    public static function attributesFromDomain(StockItemSupplier $supplier): array
    {
        $now = CarbonImmutable::now();

        return [
            'supplier_id' => $supplier->supplierId,
            'supplier_name' => $supplier->supplierName,
            'code' => $supplier->code,
            'supplier_barcode' => $supplier->supplierBarcode,
            'purchase_price' => $supplier->purchasePrice,
            'is_default' => $supplier->isDefault,
            'lead_time' => $supplier->leadTime,
            'supplier_currency' => $supplier->supplierCurrency,
            'min_price' => $supplier->minPrice,
            'max_price' => $supplier->maxPrice,
            'average_price' => $supplier->averagePrice,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
