<?php

declare(strict_types=1);

namespace Tests\Unit\Database\OrderSlice;

use App\Domain\Catalog\Order\ValueObjects\RawPaymentMethod;
use Database\Seeders\OrderSlice\EdgeCase;
use Database\Seeders\OrderSlice\EdgeCaseOrder;
use Database\Seeders\OrderSlice\EdgeCaseOrders;
use Database\Seeders\OrderSlice\ExpectedOutcome;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The edge-case list covers every row of COR-299's edge-case table with that row's expected outcome (AC2, AC3).
 */
#[CoversNothing]
final class OrderSliceEdgeCasesTest extends TestCase
{
    private const array EXPECTED_OUTCOMES = [
        'payment_method' => 'synced',
        'credit_payment_method' => 'skipped:payment_method_unmapped',
        'offline_payment_method' => 'skipped:payment_method_unmapped',
        'cancelled' => 'skipped:cancelled',
        'edited' => 'synced',
        'invoice_with_company' => 'synced',
        'sales_receipt_without_company' => 'synced',
        'name_cleaning' => 'synced',
        'name_apostrophe' => 'synced',
        'display_name_case_variant' => 'synced',
        'country_ireland' => 'synced',
        'country_ireland_variant' => 'synced',
        'zero_rated' => 'synced',
        'vat_relief' => 'synced',
        'multi_quantity_seven_dp' => 'synced',
        'discounted_shipping' => 'synced',
        'shipping_third_decimal_five' => 'synced',
        'sku_override' => 'synced',
        'missing_sku' => 'failed:item_missing',
        'zero_lines' => 'failed:zero_lines',
        'refunded' => 'synced',
        'part_refunded' => 'synced',
    ];

    #[Test]
    public function every_table_row_has_an_order_with_that_rows_expected_outcome(): void
    {
        $this->assertSame(\array_keys(self::EXPECTED_OUTCOMES), \array_map(static fn(EdgeCase $case): string => $case->value, EdgeCase::cases()));

        foreach (EdgeCaseOrders::all() as $edgeCase) {
            $this->assertSame(self::EXPECTED_OUTCOMES[$edgeCase->case->value], $edgeCase->expectedOutcome->value, $edgeCase->case->value);
        }

        $covered = \array_unique(\array_map(static fn(EdgeCaseOrder $order): string => $order->case->value, EdgeCaseOrders::all()));
        \sort($covered);
        $expected = \array_keys(self::EXPECTED_OUTCOMES);
        \sort($expected);
        $this->assertSame($expected, $covered);
    }

    #[Test]
    public function there_is_one_synced_order_per_raw_payment_method_other_than_credit_and_offline(): void
    {
        $methods = \array_map(
            static fn(EdgeCaseOrder $order): string => $order->order->paymentMethod->value,
            \array_values(\array_filter(EdgeCaseOrders::all(), static fn(EdgeCaseOrder $order): bool => $order->case === EdgeCase::PaymentMethod)),
        );

        $expected = \array_values(\array_map(
            static fn(RawPaymentMethod $method): string => $method->value,
            \array_filter(RawPaymentMethod::cases(), static fn(RawPaymentMethod $method): bool => $method !== RawPaymentMethod::Credit && $method !== RawPaymentMethod::Offline),
        ));

        $this->assertSame($expected, $methods);
    }

    #[Test]
    public function every_outcome_is_synced_or_carries_a_contract_reason_code(): void
    {
        foreach (EdgeCaseOrders::all() as $edgeCase) {
            $this->assertMatchesRegularExpression('/^(synced|skipped:[a-z_]+|failed:[a-z_]+)$/', $edgeCase->expectedOutcome->value);
        }

        $this->assertSame(
            ['synced', 'skipped:cancelled', 'skipped:payment_method_unmapped', 'failed:item_missing', 'failed:zero_lines'],
            \array_map(static fn(ExpectedOutcome $outcome): string => $outcome->value, ExpectedOutcome::cases()),
        );
    }

    #[Test]
    public function references_are_fixed_and_shared_only_by_the_edited_pair(): void
    {
        $first = \array_map(static fn(EdgeCaseOrder $order): int => $order->order->reference, EdgeCaseOrders::all());
        $second = \array_map(static fn(EdgeCaseOrder $order): int => $order->order->reference, EdgeCaseOrders::all());

        $this->assertSame($first, $second);
        $this->assertSame($first, \array_values(\array_unique($first)));

        foreach (EdgeCaseOrders::all() as $edgeCase) {
            if ($edgeCase->superseded === null) {
                continue;
            }

            $this->assertSame(EdgeCase::Edited, $edgeCase->case);
            $this->assertSame($edgeCase->order->reference, $edgeCase->superseded->reference);
            $this->assertLessThan($edgeCase->order->externalId, $edgeCase->superseded->externalId);
        }
    }
}
