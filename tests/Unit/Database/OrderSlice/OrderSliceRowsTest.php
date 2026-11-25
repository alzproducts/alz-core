<?php

declare(strict_types=1);

namespace Tests\Unit\Database\OrderSlice;

use App\Application\Enums\FixtureSku;
use App\Domain\Catalog\Order\ValueObjects\OrderStatusType;
use App\Domain\Catalog\Order\ValueObjects\RawPaymentMethod;
use Carbon\CarbonImmutable;
use Database\Seeders\OrderSlice\BulkOrderBuilder;
use Database\Seeders\OrderSlice\EdgeCaseOrders;
use Database\Seeders\OrderSlice\OrderProfile;
use Database\Seeders\OrderSlice\OrderSliceGenerator;
use Database\Seeders\OrderSlice\OrderSliceTables;
use Database\Seeders\ProductSlice\SeedIds;
use Database\Seeders\ProductSlice\SliceRows;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The generated orders-slice rows, without a database: bulk count, profile shares, dates, determinism, payment
 * methods, SKUs and customer emails (AC8, AC9, AC10, AC11, AC13, AC15).
 */
#[CoversNothing]
final class OrderSliceRowsTest extends TestCase
{
    private const int SEED = 252;

    private const int FIRST_BULK_EXTERNAL_ID = SeedIds::ORDER_BASE + 2 * 1_001;

    #[Test]
    public function without_a_profile_it_generates_exactly_200_bulk_orders_beyond_the_edge_cases(): void
    {
        $orders = self::synthetic()->rows(OrderSliceTables::ORDERS);
        $edgeCaseRows = \count(EdgeCaseOrders::all()) + \count(\array_filter(EdgeCaseOrders::all(), static fn($order): bool => $order->superseded !== null));

        $this->assertCount(BulkOrderBuilder::COUNT, self::bulk($orders));
        $this->assertSame(200, BulkOrderBuilder::COUNT);
        $this->assertCount($edgeCaseRows + 200, $orders);
    }

    #[Test]
    public function every_order_reference_is_a_4_digit_integer(): void
    {
        foreach (self::synthetic()->rows(OrderSliceTables::ORDERS) as $order) {
            $this->assertIsInt($order['reference']);
            $this->assertGreaterThanOrEqual(1_000, $order['reference']);
            $this->assertLessThanOrEqual(9_999, $order['reference']);
        }
    }

    #[Test]
    public function no_two_orders_share_a_reference_except_an_edited_order_and_its_superseded_version(): void
    {
        $rows = self::synthetic()->rows(OrderSliceTables::ORDERS);
        $superseded = \count(\array_filter(EdgeCaseOrders::all(), static fn($order): bool => $order->superseded !== null));
        $references = \array_column($rows, 'reference');

        $this->assertCount(\count($rows) - $superseded, \array_unique($references));
    }

    #[Test]
    public function every_order_is_placed_in_october_2026_london_time(): void
    {
        foreach (self::synthetic()->rows(OrderSliceTables::ORDERS) as $order) {
            $placed = CarbonImmutable::parse(self::string($order['order_placed_at']))->setTimezone('Europe/London');

            $this->assertSame('2026-10', $placed->format('Y-m'), self::string($order['order_placed_at']));
        }
    }

    #[Test]
    public function the_same_seed_gives_identical_rows_and_another_seed_moves_the_bulk_dates(): void
    {
        $first = self::synthetic();
        $second = self::synthetic();
        $other = new OrderSliceGenerator()->generate(OrderProfile::synthetic(), self::SEED + 1);

        foreach (OrderSliceTables::INSERT_ORDER as $table) {
            $this->assertSame($first->rows($table), $second->rows($table), $table);
        }

        $this->assertNotSame(self::placedAt($first), self::placedAt($other));
    }

    #[Test]
    public function every_payment_method_is_the_grouping_of_its_raw_method(): void
    {
        foreach (self::synthetic()->rows(OrderSliceTables::ORDERS) as $order) {
            $raw = RawPaymentMethod::from(self::string($order['payment_method_raw']));

            $this->assertSame($raw->toPaymentMethod()->value, $order['payment_method']);
        }
    }

    #[Test]
    public function every_line_sku_is_in_the_fixture_pool_except_the_missing_and_legacy_skus(): void
    {
        $outside = [];

        foreach (self::synthetic()->rows(OrderSliceTables::ORDER_PRODUCTS) as $line) {
            if (! \in_array($line['sku'], FixtureSku::values(), true)) {
                $outside[] = $line['sku'];
            }
        }

        \sort($outside);
        $this->assertSame([EdgeCaseOrders::LEGACY_SKU, EdgeCaseOrders::MISSING_SKU], $outside);
        $this->assertSame(
            [FixtureSku::Sku007->value],
            \array_column(self::synthetic()->rows(OrderSliceTables::ORDER_PRODUCT_EXTRA_DATA), 'sku_override'),
        );
    }

    #[Test]
    public function every_customer_email_is_an_example_com_address(): void
    {
        foreach (self::synthetic()->rows(OrderSliceTables::ORDERS) as $order) {
            $this->assertStringEndsWith('@example.com', self::string($order['billing_email']));
            $this->assertStringEndsWith('@example.com', self::string($order['delivery_email']));
        }
    }

    #[Test]
    public function profile_shares_of_one_make_every_bulk_order_zero_rated_relieved_and_refunded(): void
    {
        $rows = self::withShares(1.0);
        $lines = self::linesByOrder($rows);

        foreach (self::bulk($rows->rows(OrderSliceTables::ORDERS)) as $order) {
            $this->assertTrue($order['has_vat_relief']);
            $this->assertSame(OrderStatusType::Refunded->value, $order['status_type']);
            $this->assertSame(0.0, $order['total']);
            $this->assertContains(0, \array_column($lines[$order['external_id']], 'vat_rate'));
        }
    }

    #[Test]
    public function profile_shares_of_zero_make_no_bulk_order_zero_rated_relieved_or_refunded(): void
    {
        $rows = self::withShares(0.0);
        $lines = self::linesByOrder($rows);
        $refunded = \array_column($rows->rows(OrderSliceTables::ORDER_REFUNDS), 'order_external_id');

        foreach (self::bulk($rows->rows(OrderSliceTables::ORDERS)) as $order) {
            $this->assertFalse($order['has_vat_relief']);
            $this->assertNotContains($order['status_type'], [OrderStatusType::Refunded->value, OrderStatusType::PartRefunded->value]);
            $this->assertNotContains($order['external_id'], $refunded);
            $this->assertNotContains(0, \array_column($lines[$order['external_id']], 'vat_rate'));
        }
    }

    #[Test]
    public function profile_tuples_shape_bulk_lines_and_no_customer_field_comes_from_the_profile(): void
    {
        $rows = self::withShares(0.0);
        $tuples = 0;

        foreach (self::bulkLines($rows) as $line) {
            $tuples += (int) ($line['price'] === 10.0 && $line['price_vat'] === 2.0);
        }

        $this->assertGreaterThan(0, $tuples);

        foreach (self::bulk($rows->rows(OrderSliceTables::ORDERS)) as $order) {
            $this->assertMatchesRegularExpression('/^customer\d+@example\.com$/', self::string($order['billing_email']));
        }
    }

    private static function synthetic(): SliceRows
    {
        return new OrderSliceGenerator()->generate(OrderProfile::synthetic(), self::SEED);
    }

    private static function withShares(float $share): SliceRows
    {
        return new OrderSliceGenerator()->generate(OrderProfile::fromArray(OrderProfileFixture::withShares($share)), self::SEED);
    }

    /**
     * @param list<array<string, mixed>> $orders
     *
     * @return list<array<string, mixed>>
     */
    private static function bulk(array $orders): array
    {
        return \array_values(\array_filter($orders, static fn(array $order): bool => $order['external_id'] >= self::FIRST_BULK_EXTERNAL_ID));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function bulkLines(SliceRows $rows): array
    {
        return \array_values(\array_filter(
            $rows->rows(OrderSliceTables::ORDER_PRODUCTS),
            static fn(array $line): bool => $line['order_external_id'] >= self::FIRST_BULK_EXTERNAL_ID,
        ));
    }

    /**
     * @return array<mixed, list<array<string, mixed>>>
     */
    private static function linesByOrder(SliceRows $rows): array
    {
        $byOrder = [];

        foreach ($rows->rows(OrderSliceTables::ORDER_PRODUCTS) as $line) {
            $byOrder[$line['order_external_id']][] = $line;
        }

        return $byOrder;
    }

    /**
     * @return list<mixed>
     */
    private static function placedAt(SliceRows $rows): array
    {
        return \array_column(self::bulk($rows->rows(OrderSliceTables::ORDERS)), 'order_placed_at');
    }

    private static function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
