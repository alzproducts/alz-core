<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Models;

use App\Domain\Linnworks\ValueObjects\PurchaseOrderAdditionalCost;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * Eloquent model for linnworks.purchase_order_additional_costs table.
 *
 * @property string $id Internal UUID
 * @property string $linnworks_purchase_id FK to purchase_orders.linnworks_purchase_id
 * @property int|null $linnworks_additional_cost_item_id Linnworks cost ID (upsert key)
 * @property int|null $additional_cost_type_id
 * @property string|null $reference
 * @property float $sub_total_line_cost
 * @property float|null $tax_rate
 * @property float $tax
 * @property string|null $currency
 * @property float $conversion_rate
 * @property float $total_line_cost
 * @property bool $allocation_locked
 * @property string|null $additional_cost_type_name
 * @property bool $additional_cost_type_is_shipping_type
 * @property bool $additional_cost_type_is_partial_allocation
 * @property bool $print
 * @property string|null $allocation_method
 * @property array<int, mixed>|null $cost_allocation
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class PurchaseOrderAdditionalCostModel extends Model
{
    use HasUuids;

    protected $table = 'linnworks.purchase_order_additional_costs';

    /** Disable mass assignment protection (internal sync model, no user input). */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'linnworks_additional_cost_item_id' => 'integer',
            'additional_cost_type_id' => 'integer',
            'sub_total_line_cost' => 'float',
            'tax_rate' => 'float',
            'tax' => 'float',
            'conversion_rate' => 'float',
            'total_line_cost' => 'float',
            'allocation_locked' => 'boolean',
            'additional_cost_type_is_shipping_type' => 'boolean',
            'additional_cost_type_is_partial_allocation' => 'boolean',
            'print' => 'boolean',
            'cost_allocation' => 'array',
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
     * Convert domain PurchaseOrderAdditionalCost to model attributes for bulk upsert.
     *
     * Note: Does NOT include 'linnworks_purchase_id' — that's set by the repository.
     * Includes timestamps because bulk upsert bypasses Eloquent's automatic handling.
     *
     * @return array<string, mixed>
     */
    public static function attributesFromDomain(PurchaseOrderAdditionalCost $cost): array
    {
        $now = CarbonImmutable::now();

        return [
            'linnworks_additional_cost_item_id' => $cost->purchaseAdditionalCostItemId,
            'additional_cost_type_id' => $cost->additionalCostTypeId,
            'reference' => $cost->reference,
            'sub_total_line_cost' => $cost->subTotalLineCost,
            'tax_rate' => $cost->taxRate?->percentage,
            'tax' => $cost->tax,
            'currency' => $cost->currency,
            'conversion_rate' => $cost->conversionRate,
            'total_line_cost' => $cost->totalLineCost,
            'allocation_locked' => $cost->allocationLocked,
            'additional_cost_type_name' => $cost->additionalCostTypeName,
            'additional_cost_type_is_shipping_type' => $cost->additionalCostTypeIsShippingType,
            'additional_cost_type_is_partial_allocation' => $cost->additionalCostTypeIsPartialAllocation,
            'print' => $cost->print,
            'allocation_method' => $cost->allocationMethod,
            'cost_allocation' => $cost->costAllocation !== null && $cost->costAllocation !== [] ? $cost->costAllocation : null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
