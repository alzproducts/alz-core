<?php

declare(strict_types=1);

namespace Database\Seeders\OrderSlice;

use App\Infrastructure\Shopwired\Mappers\StatusTypeToLifecycleMapper;
use Database\Seeders\ProductSlice\Builders\SeedRandom;
use Database\Seeders\ProductSlice\SeedIds;
use Database\Seeders\ProductSlice\SliceRows;

/**
 * Turns order specs into orders-slice rows. Line and refund external IDs are allocated in spec order.
 */
final class OrderRowBuilder
{
    /** @var list<array<string, mixed>> */
    private array $orders = [];

    /** @var list<array<string, mixed>> */
    private array $lines = [];

    /** @var list<array<string, mixed>> */
    private array $refunds = [];

    /** @var list<array<string, mixed>> */
    private array $extraData = [];

    private int $nextLineId = SeedIds::ORDER_LINE_BASE + 1;

    private int $nextRefundId = SeedIds::ORDER_REFUND_BASE + 1;

    public function __construct(
        private readonly SeedRandom $random,
    ) {}

    public function add(OrderSpec $order): void
    {
        $orderId = $this->random->uuid();
        $placedAt = self::timestamp($order->placedAt);

        $this->orders[] = $this->orderRow($order, $orderId, $placedAt);

        foreach ($order->lines as $line) {
            $this->addLine($order, $line, $orderId, $placedAt);
        }

        foreach ($order->refunds as $value) {
            $this->refunds[] = [
                'id' => $this->random->uuid(),
                'order_id' => $orderId,
                'order_external_id' => $order->externalId,
                'external_id' => $this->nextRefundId++,
                'name' => 'Refund',
                'value' => $value,
                'created_at_shopwired' => $placedAt,
                'created_at' => $placedAt,
                'updated_at' => $placedAt,
            ];
        }
    }

    public function rows(): SliceRows
    {
        return new SliceRows([
            OrderSliceTables::ORDERS => $this->orders,
            OrderSliceTables::ORDER_PRODUCTS => $this->lines,
            OrderSliceTables::ORDER_REFUNDS => $this->refunds,
            OrderSliceTables::ORDER_PRODUCT_EXTRA_DATA => $this->extraData,
            OrderSliceTables::ORDER_DISCOUNTS => [],
            OrderSliceTables::ORDER_ADMIN_COMMENTS => [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function orderRow(OrderSpec $order, string $orderId, string $placedAt): array
    {
        $customer = $order->customer;

        return [
            'id' => $orderId,
            'external_id' => $order->externalId,
            'reference' => $order->reference,
            'total' => OrderTotals::total($order->lines, $order->shippingNet, $order->refunds),
            'sub_total_net' => OrderTotals::subTotalNet($order->lines),
            'shipping_total_net' => $order->shippingNet,
            'original_shipping_total_net' => $order->originalShippingNet ?? $order->shippingNet,
            'shipping_charge_net' => $order->shippingNet,
            'shipping_vat_rate' => OrderTotals::shippingVatRate($order->lines),
            'shipping_method' => 'Standard Delivery',
            'tax_value' => OrderTotals::taxValue($order->lines, $order->shippingNet),
            'status_name' => $order->status->value,
            'status_type' => $order->status->value,
            'lifecycle_status' => StatusTypeToLifecycleMapper::toLifecycle($order->status)->value,
            'customer_id' => $customer->id,
            'customer_type' => 0,
            ...self::address('billing', $customer),
            ...self::address('delivery', $customer),
            'payment_method' => $order->paymentMethod->toPaymentMethod()->value,
            'payment_method_raw' => $order->paymentMethod->value,
            'has_vat_relief' => $order->hasVatRelief,
            'order_placed_at' => $placedAt,
            'created_at' => $placedAt,
            'updated_at' => $placedAt,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private static function address(string $prefix, Customer $customer): array
    {
        return [
            $prefix . '_name' => $customer->name,
            $prefix . '_email' => $customer->email,
            $prefix . '_company' => $customer->company,
            $prefix . '_address_line1' => $customer->addressLine1,
            $prefix . '_city' => $customer->city,
            $prefix . '_postcode' => $customer->postcode,
            $prefix . '_country' => $customer->country,
        ];
    }

    private function addLine(OrderSpec $order, LineSpec $line, string $orderId, string $placedAt): void
    {
        $lineId = $this->nextLineId++;

        $this->lines[] = [
            'id' => $this->random->uuid(),
            'order_id' => $orderId,
            'order_external_id' => $order->externalId,
            'external_id' => $lineId,
            'title' => 'Fixture product ' . $line->sku,
            'sku' => $line->sku,
            'price' => $line->price,
            'price_vat' => $line->priceVat,
            'total' => \round($line->price * $line->quantity, 3),
            'total_vat' => \round($line->priceVat * $line->quantity, 3),
            'original_price' => $line->price,
            'quantity' => $line->quantity,
            'vat_rate' => $line->vatRate,
            'created_at' => $placedAt,
            'updated_at' => $placedAt,
        ];

        if ($line->skuOverride !== null) {
            $this->extraData[] = [
                'id' => $this->random->uuid(),
                'order_external_id' => $order->externalId,
                'external_id' => $lineId,
                'variation_hash' => null,
                'sku_override' => $line->skuOverride,
                'created_at' => $placedAt,
                'updated_at' => $placedAt,
            ];
        }
    }

    private static function timestamp(int $unixSeconds): string
    {
        return \gmdate('Y-m-d H:i:sP', $unixSeconds);
    }
}
