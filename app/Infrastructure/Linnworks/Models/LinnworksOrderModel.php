<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for linnworks.orders table.
 *
 * Stores Linnworks processed orders synced from the v2 GetOrders API.
 * The `linnworks_order_id` is Linnworks' GUID, while `id` is our internal UUID.
 *
 * @property string $id Internal UUID
 * @property string $linnworks_order_id Linnworks GUID
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
 * @property bool $hold_or_cancel
 * @property int|null $marker
 * @property bool $is_parked
 * @property string $source
 * @property string $sub_source
 * @property CarbonImmutable|null $despatch_by_date
 * @property string $fulfilment_location_id
 * @property string $location
 * @property array<int, string> $folder_names
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
            'hold_or_cancel' => 'boolean',
            'marker' => 'integer',
            'is_parked' => 'boolean',
            'despatch_by_date' => 'immutable_datetime',
            'folder_names' => 'array',
            'total_charge' => 'float',
            'subtotal' => 'float',
            'tax' => 'float',
            'postage_cost' => 'float',
            'postage_cost_ex_tax' => 'float',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
