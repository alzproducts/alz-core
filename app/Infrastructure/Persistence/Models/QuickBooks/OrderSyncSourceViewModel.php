<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models\QuickBooks;

use App\Application\QuickBooks\OrderSync\DTOs\OrderContactDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SourceOrderRowDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SourceOrderRowLineDTO;
use App\Domain\Catalog\Order\ValueObjects\OrderLifecycleStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @phpstan-type SourceLine array{sku: string, title: string, quantity: int, price: string, price_vat: string, vat_rate: string|null}
 *
 * @property int $reference
 * @property int $external_id
 * @property CarbonImmutable $order_placed_at
 * @property OrderLifecycleStatus $lifecycle_status
 * @property string|null $payment_method_raw
 * @property string $billing_name
 * @property string|null $billing_company
 * @property string $billing_address_line1
 * @property string|null $billing_address_line2
 * @property string|null $billing_address_line3
 * @property string $billing_city
 * @property string $billing_postcode
 * @property string $billing_country
 * @property string $billing_email
 * @property string|null $billing_telephone
 * @property string $delivery_name
 * @property string|null $delivery_company
 * @property string $delivery_address_line1
 * @property string|null $delivery_address_line2
 * @property string|null $delivery_address_line3
 * @property string $delivery_city
 * @property string $delivery_postcode
 * @property string $delivery_country
 * @property string|null $shipping_method
 * @property string $shipping_total_net
 * @property string|null $shipping_vat_rate
 * @property string|null $comments
 * @property string|null $transaction_id
 * @property list<SourceLine> $lines
 */
final class OrderSyncSourceViewModel extends Model
{
    protected $table = 'quickbooks.order_sync_source';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'reference';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'reference' => 'integer',
            'external_id' => 'integer',
            'order_placed_at' => 'immutable_datetime',
            'lifecycle_status' => OrderLifecycleStatus::class,
            'lines' => 'array',
        ];
    }

    public function toDomain(): SourceOrderRowDTO
    {
        return new SourceOrderRowDTO(
            reference: $this->reference,
            externalId: $this->external_id,
            createdAt: $this->order_placed_at,
            status: $this->lifecycle_status,
            paymentMethod: $this->payment_method_raw,
            billing: $this->billing(),
            billingEmail: $this->billing_email,
            billingPhone: $this->billing_telephone ?? '',
            delivery: $this->delivery(),
            shippingMethod: $this->shipping_method ?? '',
            shippingTotalNet: $this->shipping_total_net,
            shippingVatRate: $this->shipping_vat_rate,
            comments: $this->comments ?? '',
            transactionId: $this->transaction_id ?? '',
            lines: \array_map(self::line(...), $this->lines),
        );
    }

    private function billing(): OrderContactDTO
    {
        return new OrderContactDTO(
            name: $this->billing_name,
            company: $this->billing_company ?? '',
            line1: $this->billing_address_line1,
            line2: $this->billing_address_line2 ?? '',
            line3: $this->billing_address_line3 ?? '',
            city: $this->billing_city,
            postcode: $this->billing_postcode,
            country: $this->billing_country,
        );
    }

    private function delivery(): OrderContactDTO
    {
        return new OrderContactDTO(
            name: $this->delivery_name,
            company: $this->delivery_company ?? '',
            line1: $this->delivery_address_line1,
            line2: $this->delivery_address_line2 ?? '',
            line3: $this->delivery_address_line3 ?? '',
            city: $this->delivery_city,
            postcode: $this->delivery_postcode,
            country: $this->delivery_country,
        );
    }

    /**
     * @param SourceLine $line
     */
    private static function line(array $line): SourceOrderRowLineDTO
    {
        return new SourceOrderRowLineDTO(
            $line['sku'],
            $line['title'],
            $line['quantity'],
            $line['price'],
            $line['price_vat'],
            $line['vat_rate'],
        );
    }
}
