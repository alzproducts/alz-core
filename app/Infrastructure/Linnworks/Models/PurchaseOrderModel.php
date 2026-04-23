<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Models;

use App\Domain\Linnworks\ValueObjects\PurchaseOrderCore;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * Eloquent model for linnworks.purchase_orders table.
 *
 * Stores Linnworks purchase orders synced via the Phase 1 data layer.
 * The `linnworks_purchase_id` is Linnworks' GUID; `id` is our internal UUID.
 *
 * @property string $id Internal UUID
 * @property string $linnworks_purchase_id External Linnworks GUID (upsert key)
 * @property string $fk_supplier_id
 * @property string $fk_location_id
 * @property string $external_invoice_number
 * @property string $status
 * @property bool $locked
 * @property int $line_count
 * @property int $delivered_lines_count
 * @property string $currency
 * @property string $supplier_reference_number
 * @property int $unit_amount_tax_included_type
 * @property float $postage_paid
 * @property float $total_cost
 * @property float $tax_paid
 * @property float|null $shipping_tax_rate
 * @property float $conversion_rate
 * @property float $converted_shipping_cost
 * @property float $converted_shipping_tax
 * @property float $converted_other_cost
 * @property float $converted_other_tax
 * @property float $converted_grand_total
 * @property CarbonImmutable|null $date_of_purchase
 * @property CarbonImmutable|null $date_of_delivery
 * @property CarbonImmutable|null $quoted_delivery_date
 * @property int $note_count
 * @property CarbonImmutable $synced_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class PurchaseOrderModel extends Model
{
    use HasUuids;

    protected $table = 'linnworks.purchase_orders';

    /** Disable mass assignment protection (internal sync model, no user input). */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'locked' => 'boolean',
            'line_count' => 'integer',
            'delivered_lines_count' => 'integer',
            'unit_amount_tax_included_type' => 'integer',
            'postage_paid' => 'float',
            'total_cost' => 'float',
            'tax_paid' => 'float',
            'shipping_tax_rate' => 'float',
            'conversion_rate' => 'float',
            'converted_shipping_cost' => 'float',
            'converted_shipping_tax' => 'float',
            'converted_other_cost' => 'float',
            'converted_other_tax' => 'float',
            'converted_grand_total' => 'float',
            'date_of_purchase' => 'immutable_datetime',
            'date_of_delivery' => 'immutable_datetime',
            'quoted_delivery_date' => 'immutable_datetime',
            'note_count' => 'integer',
            'synced_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Build attribute row from a PurchaseOrderCore domain VO.
     *
     * Takes Core (not Header) because note_count lives on Core — Core is the
     * domain representation of the purchase_orders row.
     *
     * @return array<string, mixed>
     */
    public static function attributesFromDomain(PurchaseOrderCore $core): array
    {
        $header = $core->header;

        return [
            'linnworks_purchase_id' => $header->pkPurchaseId->value,
            'fk_supplier_id' => $header->fkSupplierId->value,
            'fk_location_id' => $header->fkLocationId->value,
            'external_invoice_number' => $header->externalInvoiceNumber,
            'status' => $header->status->value,
            'locked' => $header->locked,
            'line_count' => $header->lineCount,
            'delivered_lines_count' => $header->deliveredLinesCount,
            'currency' => $header->currency,
            'supplier_reference_number' => $header->supplierReferenceNumber,
            'unit_amount_tax_included_type' => $header->unitAmountTaxIncludedType,
            'postage_paid' => $header->postagePaid->toNet(),
            'total_cost' => $header->totalCost,
            'tax_paid' => $header->taxPaid,
            'shipping_tax_rate' => $header->shippingTaxRate?->percentage,
            'conversion_rate' => $header->conversionRate,
            'converted_shipping_cost' => $header->convertedShippingCost,
            'converted_shipping_tax' => $header->convertedShippingTax,
            'converted_other_cost' => $header->convertedOtherCost,
            'converted_other_tax' => $header->convertedOtherTax,
            'converted_grand_total' => $header->convertedGrandTotal,
            'date_of_purchase' => $header->dateOfPurchase,
            'date_of_delivery' => $header->dateOfDelivery,
            'quoted_delivery_date' => $header->quotedDeliveryDate,
            'note_count' => $core->noteCount,
            'synced_at' => CarbonImmutable::now(),
        ];
    }

    /**
     * @return HasMany<PurchaseOrderItemModel, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(
            PurchaseOrderItemModel::class,
            'linnworks_purchase_id',
            'linnworks_purchase_id',
        );
    }

    /**
     * @return HasMany<PurchaseOrderAdditionalCostModel, $this>
     */
    public function additionalCosts(): HasMany
    {
        return $this->hasMany(
            PurchaseOrderAdditionalCostModel::class,
            'linnworks_purchase_id',
            'linnworks_purchase_id',
        );
    }

    /**
     * @return HasMany<PurchaseOrderDeliveredRecordModel, $this>
     */
    public function deliveredRecords(): HasMany
    {
        return $this->hasMany(
            PurchaseOrderDeliveredRecordModel::class,
            'linnworks_purchase_id',
            'linnworks_purchase_id',
        );
    }

    /**
     * @return HasMany<PurchaseOrderNoteModel, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(
            PurchaseOrderNoteModel::class,
            'linnworks_purchase_id',
            'linnworks_purchase_id',
        );
    }

    /**
     * @return HasMany<PurchaseOrderExtendedPropertyModel, $this>
     */
    public function extendedProperties(): HasMany
    {
        return $this->hasMany(
            PurchaseOrderExtendedPropertyModel::class,
            'linnworks_purchase_id',
            'linnworks_purchase_id',
        );
    }
}
