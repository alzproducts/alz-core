<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Seeds postable ShopWired orders, each with one line.
 */
trait InsertsSourceOrders
{
    /**
     * @param array<string, mixed> $state
     */
    private function insertOrder(int $reference, string $placedAt = '2026-11-01 10:00:00+00', array $state = [], string $sku = 'SKU-10'): void
    {
        $orderId = DB::table('shopwired.orders')->insertGetId([
            'external_id' => $reference,
            'reference' => $reference,
            'total' => '29.990000',
            'sub_total_net' => '25.000000',
            'shipping_total_net' => '4.990000',
            'shipping_vat_rate' => '20.0',
            'shipping_method' => 'Next Day',
            'status_name' => 'Processing',
            'status_type' => 'processing',
            'lifecycle_status' => 'processing',
            'customer_id' => 1,
            'customer_type' => 1,
            'billing_name' => 'Test Buyer',
            'billing_email' => 'buyer@example.test',
            'billing_address_line1' => '1 Test Street',
            'billing_city' => 'Testville',
            'billing_postcode' => 'TE1 1ST',
            'billing_country' => 'United Kingdom',
            'delivery_name' => 'Test Buyer',
            'delivery_email' => 'buyer@example.test',
            'delivery_address_line1' => '1 Test Street',
            'delivery_city' => 'Testville',
            'delivery_postcode' => 'TE1 1ST',
            'delivery_country' => 'United Kingdom',
            'shipping_charge_net' => '4.990000',
            'payment_method' => 'card',
            'payment_method_raw' => 'Opayo Hosted',
            'order_placed_at' => $placedAt,
            'created_at' => $placedAt,
            'updated_at' => $placedAt,
            ...$state,
        ]);
        DB::table('shopwired.order_products')->insert([
            'order_id' => $orderId,
            'order_external_id' => $reference,
            'external_id' => 10,
            'title' => 'Line',
            'sku' => $sku,
            'price' => '12.5',
            'price_vat' => '2.5',
            'total' => '25.0',
            'total_vat' => '5.0',
            'original_price' => '12.5',
            'quantity' => 2,
            'vat_rate' => '20',
            'created_at' => $placedAt,
            'updated_at' => $placedAt,
        ]);
    }
}
