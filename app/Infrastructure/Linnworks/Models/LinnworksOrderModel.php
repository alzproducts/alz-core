<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Models;

use App\Domain\Linnworks\ValueObjects\LinnworksOrder;
use App\Domain\Linnworks\ValueObjects\LinnworksOrderNote;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for linnworks.orders table.
 *
 * Stores Linnworks processed orders synced from the v2 GetOrders API.
 * The `linnworks_order_id` is Linnworks' GUID, while `id` is our internal UUID.
 *
 * @property string $id Internal UUID
 * @property string $linnworks_order_id External Linnworks GUID (upsert key)
 * @property int $num_order_id
 * @property bool $processed
 * @property CarbonImmutable $last_updated
 * @property CarbonImmutable|null $processed_on
 * @property CarbonImmutable|null $paid_on
 * @property CarbonImmutable|null $received_date
 * @property string $reference_num
 * @property string $external_reference_num
 * @property string $secondary_reference
 * @property int $status
 * @property bool $is_cancelled
 * @property bool $hold_or_cancel
 * @property int|null $marker
 * @property bool $is_parked
 * @property string $source
 * @property string $sub_source
 * @property CarbonImmutable|null $despatch_by_date
 * @property string $fulfilment_location_id
 * @property string $location
 * @property array<int, string> $folder_names
 * @property array<int, mixed>|null $notes
 * @property float $total_charge
 * @property float $subtotal
 * @property float $tax
 * @property string $payment_method
 * @property string $postal_service_name
 * @property string $vendor
 * @property float $postage_cost
 * @property float $postage_cost_ex_tax
 * @property string $tracking_number
 * @property string $channel_buyer_name
 * @property string $ship_email
 * @property string $ship_full_name
 * @property string $ship_company
 * @property string $ship_address1
 * @property string $ship_address2
 * @property string $ship_address3
 * @property string $ship_town
 * @property string $ship_postcode
 * @property string $ship_country
 * @property string $bill_email
 * @property string $bill_full_name
 * @property string $bill_company
 * @property string $bill_address1
 * @property string $bill_address2
 * @property string $bill_address3
 * @property string $bill_town
 * @property string $bill_postcode
 * @property string $bill_country
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class LinnworksOrderModel extends Model
{
    use HasUuids;

    protected $table = 'linnworks.orders';

    /** Disable mass assignment protection (internal sync model, no user input). */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'num_order_id' => 'integer',
            'processed' => 'boolean',
            'last_updated' => 'immutable_datetime',
            'processed_on' => 'immutable_datetime',
            'paid_on' => 'immutable_datetime',
            'received_date' => 'immutable_datetime',
            'status' => 'integer',
            'is_cancelled' => 'boolean',
            'hold_or_cancel' => 'boolean',
            'marker' => 'integer',
            'is_parked' => 'boolean',
            'despatch_by_date' => 'immutable_datetime',
            'folder_names' => 'array',
            'notes' => 'array',
            'total_charge' => 'float',
            'subtotal' => 'float',
            'tax' => 'float',
            'postage_cost' => 'float',
            'postage_cost_ex_tax' => 'float',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Build attribute row from a LinnworksOrder domain VO.
     *
     * Notes are stored as a JSONB column (no independent queryability); items and
     * extended properties live in separate tables and are synced by the repository.
     *
     * @return array<string, mixed>
     */
    public static function attributesFromDomain(LinnworksOrder $order): array
    {
        return [
            'linnworks_order_id' => $order->orderId->value,
            'num_order_id' => $order->numOrderId->value,
            'processed' => $order->processed,
            'last_updated' => $order->lastUpdated,
            'processed_on' => $order->processedOn,
            'paid_on' => $order->paidOn,
            'received_date' => $order->receivedDate,

            // GeneralInfo
            'reference_num' => $order->referenceNum,
            'external_reference_num' => $order->externalReferenceNum,
            'secondary_reference' => $order->secondaryReference,
            'status' => $order->status,
            'is_cancelled' => $order->isCancelled,
            'hold_or_cancel' => $order->holdOrCancel,
            'marker' => $order->marker,
            'is_parked' => $order->isParked,
            'source' => $order->source,
            'sub_source' => $order->subSource,
            'despatch_by_date' => $order->despatchByDate,
            'fulfilment_location_id' => $order->fulfilmentLocationId,
            'location' => $order->location,
            'folder_names' => $order->folderNames,

            // Notes (JSONB)
            'notes' => \array_map(
                static fn(LinnworksOrderNote $note): array => $note->toArray(),
                $order->notes,
            ),

            // TotalsInfo
            'total_charge' => $order->totalCharge,
            'subtotal' => $order->subtotal,
            'tax' => $order->tax,
            'payment_method' => $order->paymentMethod,
            'payment_method_id' => $order->paymentMethodId->value,
            'currency' => $order->currency,

            // ShippingInfo
            'postal_service_name' => $order->postalServiceName,
            'vendor' => $order->vendor,
            'postage_cost' => $order->postageCost,
            'postage_cost_ex_tax' => $order->postageCostExTax,
            'tracking_number' => $order->trackingNumber,

            // CustomerInfo — Shipping
            'channel_buyer_name' => $order->channelBuyerName,
            'ship_email' => $order->shipEmail,
            'ship_full_name' => $order->shipFullName,
            'ship_company' => $order->shipCompany,
            'ship_address1' => $order->shipAddress1,
            'ship_address2' => $order->shipAddress2,
            'ship_address3' => $order->shipAddress3,
            'ship_town' => $order->shipTown,
            'ship_postcode' => $order->shipPostcode,
            'ship_country' => $order->shipCountry,

            // CustomerInfo — Billing
            'bill_email' => $order->billEmail,
            'bill_full_name' => $order->billFullName,
            'bill_company' => $order->billCompany,
            'bill_address1' => $order->billAddress1,
            'bill_address2' => $order->billAddress2,
            'bill_address3' => $order->billAddress3,
            'bill_town' => $order->billTown,
            'bill_postcode' => $order->billPostcode,
            'bill_country' => $order->billCountry,
        ];
    }

    /**
     * @return HasMany<LinnworksOrderItemModel, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(
            LinnworksOrderItemModel::class,
            'linnworks_order_id',
            'linnworks_order_id',
        );
    }

    /**
     * @return HasMany<LinnworksOrderExtendedPropertyModel, $this>
     */
    public function extendedProperties(): HasMany
    {
        return $this->hasMany(
            LinnworksOrderExtendedPropertyModel::class,
            'linnworks_order_id',
            'linnworks_order_id',
        );
    }
}
