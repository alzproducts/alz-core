<?php

declare(strict_types=1);

namespace Tests\Unit\Database\OrderSlice;

/**
 * A synthetic orders profile in the export's shape. Tests never read the real profile (COR-299 pitfall P1).
 */
final class OrderProfileFixture
{
    /**
     * @return array<string, mixed>
     */
    public static function withShares(float $share): array
    {
        $shares = [];

        foreach (['cancelled', 'part_refunded', 'vat_relief', 'zero_rated_line', 'refunded'] as $key) {
            $shares[$key] = ['share' => $key === 'cancelled' || $key === 'part_refunded' ? 0 : $share];
        }

        return [
            'distributions' => [
                'payment_method' => ['rows' => [
                    ['payment_method' => 'card', 'orders' => 6],
                    ['payment_method' => 'paypal', 'orders' => 3],
                    ['payment_method' => 'admin', 'orders' => 1],
                    ['payment_method' => null, 'orders' => 1],
                ]],
                'lines_per_order' => ['rows' => [
                    ['line_count' => 0, 'orders' => 1],
                    ['line_count' => 1, 'orders' => 5],
                    ['line_count' => 3, 'orders' => 2],
                ]],
                'qty' => ['rows' => [['qty' => 1, 'lines' => 8], ['qty' => 2, 'lines' => 2]], 'suppressed' => 1],
                'price_tuples' => ['rows' => [
                    ['price' => 10.0, 'price_vat' => 2.0, 'vat_rate' => 20, 'lines' => 5],
                    ['price' => 7.5, 'price_vat' => 0, 'vat_rate' => 0, 'lines' => 5],
                ], 'suppressed' => 2],
                'destination_class' => ['rows' => [
                    ['destination_class' => 'uk', 'orders' => 8],
                    ['destination_class' => 'ireland', 'orders' => 1],
                    ['destination_class' => 'rest_of_eu', 'orders' => 1],
                ]],
            ],
            'shares' => $shares,
        ];
    }
}
