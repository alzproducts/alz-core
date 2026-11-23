<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models\QuickBooks;

use App\Application\QuickBooks\OrderSync\DTOs\OrderContactDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SourceOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SyncOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SyncOrderLineDTO;
use App\Application\QuickBooks\OrderSync\Enums\SourceOrderDefect;
use App\Domain\Catalog\Order\ValueObjects\OrderLifecycleStatus;
use App\Domain\Catalog\Order\ValueObjects\RawPaymentMethod;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Shared\Money\ValueObjects\DecimalMoney;
use App\Domain\ValueObjects\TaxRate;
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

    public function toDomain(): SourceOrderDTO
    {
        $paymentMethod = RawPaymentMethod::tryFrom($this->payment_method_raw ?? '');
        $defect = $paymentMethod === null ? SourceOrderDefect::PaymentMethodMissing : $this->contentDefect();

        return new SourceOrderDTO(
            reference: $this->reference,
            externalId: $this->external_id,
            placedAt: $this->order_placed_at,
            status: $this->lifecycle_status,
            order: $paymentMethod === null || $defect !== null ? null : $this->syncOrder($paymentMethod),
            defect: $defect,
        );
    }

    private function contentDefect(): ?SourceOrderDefect
    {
        return match (true) {
            \array_any($this->lines, static fn(array $line): bool => \mb_trim($line['sku']) === '') => SourceOrderDefect::SkuMissing,
            $this->hasInvalidVatRate() => SourceOrderDefect::VatRateInvalid,
            \array_any($this->lines, static fn(array $line): bool => $line['quantity'] < 1) => SourceOrderDefect::QuantityInvalid,
            default => null,
        };
    }

    private function hasInvalidVatRate(): bool
    {
        // intentional: free shipping needs no rate, and an order with no lines is the builder's `zero_lines`.
        $shippingRate = $this->shipping_vat_rate ?? ($this->lines !== [] && $this->chargesShipping() ? null : '0');

        return self::isInvalidRate($shippingRate)
            || \array_any($this->lines, static fn(array $line): bool => self::isInvalidRate($line['vat_rate']));
    }

    private function chargesShipping(): bool
    {
        return DecimalMoney::fromString($this->shipping_total_net)->compare(DecimalMoney::fromString('0')) !== 0;
    }

    private static function isInvalidRate(?string $rate): bool
    {
        return $rate === null || (float) $rate < 0;
    }

    private function syncOrder(RawPaymentMethod $paymentMethod): SyncOrderDTO
    {
        return new SyncOrderDTO(
            reference: (string) $this->reference,
            createdAt: $this->order_placed_at,
            paymentMethod: $paymentMethod,
            billing: $this->billing(),
            billingEmail: $this->billing_email,
            billingPhone: $this->billing_telephone ?? '',
            delivery: $this->delivery(),
            shippingMethod: $this->shipping_method ?? '',
            shippingTotalNet: DecimalMoney::fromString($this->shipping_total_net),
            shippingVatRate: self::taxRate($this->shipping_vat_rate),
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
    private static function line(array $line): SyncOrderLineDTO
    {
        return new SyncOrderLineDTO(
            Sku::fromTrusted($line['sku']),
            $line['title'],
            $line['quantity'],
            DecimalMoney::fromString($line['price']),
            DecimalMoney::fromString($line['price_vat']),
            self::taxRate($line['vat_rate']),
        );
    }

    private static function taxRate(?string $percentage): ?TaxRate
    {
        return $percentage === null ? null : TaxRate::fromPercentage((float) $percentage);
    }
}
