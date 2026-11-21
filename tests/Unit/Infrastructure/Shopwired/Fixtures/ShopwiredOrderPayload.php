<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired\Fixtures;

/**
 * Minimal ShopWired order API object (snake_case wire keys) that `OrderResponse::from()` accepts.
 */
final class ShopwiredOrderPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function order(
        int $id,
        string $paymentMethod,
        string $created = '2026-03-15T10:00:00+00:00',
    ): array {
        return [
            'id' => $id,
            'reference' => $id,
            'created' => $created,
            'archived' => false,
            'anonymized' => false,
            'pre_order' => false,
            'payment_method' => $paymentMethod,
            'total' => 120.0,
            'sub_total' => 100.0,
            'shipping_total' => 0.0,
            'original_shipping_total' => 0.0,
            'partial_payment_total' => 0.0,
            'marketing' => false,
            'comments' => null,
            'tracking_url' => null,
            'referrer_id' => 0,
            'earned_reward_points' => 0.0,
            'line_item_vat_calculation' => false,
            'status' => ['id' => 1, 'name' => 'Paid', 'type' => 'paid', 'sort_order' => 1],
            'billing_address' => self::address(),
            'shipping_address' => self::address(),
            'customer' => ['id' => 501, 'type' => 0, 'date_of_birth' => null],
            'products' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function address(): array
    {
        return [
            'name' => 'Test Buyer',
            'email_address' => 'buyer@example.test',
            'telephone' => null,
            'company_name' => null,
            'address_line_1' => '1 Test Street',
            'address_line_2' => null,
            'address_line_3' => null,
            'city' => 'Testtown',
            'province' => null,
            'state' => null,
            'postcode' => 'TE1 1ST',
            'country' => 'United Kingdom',
            'country_id' => 1,
        ];
    }
}
