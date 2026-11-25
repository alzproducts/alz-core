<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\OrderSyncSourceQueryRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderContactDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SourceOrderDTO;
use App\Application\QuickBooks\OrderSync\Enums\SourceOrderDefect;
use App\Application\QuickBooks\OrderSync\Resolvers\OrderSyncCalendarResolver;
use App\Application\QuickBooks\OrderSync\Transformers\SourceOrderTransformer;
use App\Domain\Catalog\Order\ValueObjects\OrderLifecycleStatus;
use App\Domain\Catalog\Order\ValueObjects\RawPaymentMethod;
use App\Infrastructure\Persistence\Models\QuickBooks\OrderSyncSourceViewModel;
use App\Infrastructure\QuickBooks\EloquentOrderSyncSourceQueryRepository;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(EloquentOrderSyncSourceQueryRepository::class)]
#[CoversClass(OrderSyncSourceViewModel::class)]
final class EloquentOrderSyncSourceQueryRepositoryTest extends TestCase
{
    use DatabaseTransactions;

    private const int REFERENCE = 2147450601;

    private const int EXTERNAL_ID = 2147450611;

    private const int OTHER_REFERENCE = 2147450602;

    private const int OTHER_EXTERNAL_ID = 2147450612;

    private OrderSyncSourceQueryRepositoryInterface $repository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->app->make(OrderSyncSourceQueryRepositoryInterface::class);
    }

    #[Test]
    public function a_complete_order_is_read_with_exact_money_and_its_percentage_rates(): void
    {
        $this->insertOrder(['billing_company' => 'Care Ltd', 'transaction_id' => 'tx-1'], [
            ['price' => '8.333333', 'price_vat' => '1.666667', 'quantity' => 3],
            ['external_id' => 20, 'sku' => 'SKU-20', 'title' => 'Second', 'vat_rate' => '0'],
        ]);
        $this->insertOrder(['external_id' => self::EXTERNAL_ID - 1, 'lifecycle_status' => 'cancelled'], []);

        $source = $this->find();

        self::assertSame(self::REFERENCE, $source->reference);
        self::assertSame(self::EXTERNAL_ID, $source->externalId);
        self::assertEquals(new DateTimeImmutable('2026-11-01 10:00:00+00'), $source->placedAt);
        self::assertSame(OrderLifecycleStatus::Processing, $source->status);
        self::assertNull($source->defect);

        $order = $source->order;
        self::assertNotNull($order);
        self::assertSame((string) self::REFERENCE, $order->reference);
        self::assertEquals($source->placedAt, $order->createdAt);
        self::assertSame(RawPaymentMethod::OpayoHosted, $order->paymentMethod);
        self::assertEquals(new OrderContactDTO('Bill Buyer', 'Care Ltd', '1 Bill Street', '', '', 'Billville', 'BI1 1LL', 'United Kingdom'), $order->billing);
        self::assertEquals(new OrderContactDTO('Del Buyer', '', '2 Del Street', 'Flat 2', '', 'Delville', 'DE1 1LL', 'Ireland'), $order->delivery);
        self::assertSame(['bill@example.test', '', 'Next Day', '', 'tx-1'], [$order->billingEmail, $order->billingPhone, $order->shippingMethod, $order->comments, $order->transactionId]);

        $view = DB::table('quickbooks.order_sync_source')->where('reference', self::REFERENCE)->sole();
        self::assertSame($view->shipping_total_net, $order->shippingTotalNet->toString());
        self::assertSame(20.0, (float) $view->shipping_vat_rate);
        self::assertSame(20.0, $order->shippingVatRate?->percentage);

        self::assertCount(2, $order->lines);
        [$first, $second] = $order->lines;
        self::assertSame(['SKU-10', 'Line', 3], [$first->sku->value, $first->title, $first->quantity]);
        self::assertSame(['8.333333', '1.666667', 20.0], [$first->unitPrice->toString(), $first->unitPriceVat->toString(), $first->vatRate?->percentage]);
        self::assertSame(['SKU-20', 0.0], [$second->sku->value, $second->vatRate?->percentage]);
    }

    #[Test]
    public function free_shipping_without_a_rate_and_an_order_without_lines_are_complete(): void
    {
        $this->insertOrder(['shipping_total_net' => '0', 'shipping_vat_rate' => null], [[]]);
        $this->insertOrder(['reference' => self::OTHER_REFERENCE, 'external_id' => self::OTHER_EXTERNAL_ID, 'shipping_vat_rate' => null], []);

        $orders = \array_map(SourceOrderTransformer::fromRow(...), $this->repository->findByReferences([self::REFERENCE, self::OTHER_REFERENCE]));

        self::assertNull($orders[self::REFERENCE]->defect);
        self::assertNotNull($orders[self::REFERENCE]->order);
        self::assertNull($orders[self::REFERENCE]->order->shippingVatRate);
        self::assertNull($orders[self::OTHER_REFERENCE]->defect);
        self::assertSame([], $orders[self::OTHER_REFERENCE]->order?->lines);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<array<string, mixed>>, SourceOrderDefect}>
     */
    public static function defects(): iterable
    {
        $payment = ['payment_method_raw' => null];
        $missingRate = ['shipping_vat_rate' => null];

        yield 'payment method missing' => [$payment, [[]], SourceOrderDefect::PaymentMethodMissing];
        yield 'sku missing' => [[], [['sku' => '']], SourceOrderDefect::SkuMissing];
        yield 'sku blank' => [[], [['sku' => '  ']], SourceOrderDefect::SkuMissing];
        yield 'vat rate missing' => [$missingRate, [[]], SourceOrderDefect::VatRateInvalid];
        yield 'line vat rate negative' => [[], [['vat_rate' => '-1']], SourceOrderDefect::VatRateInvalid];
        yield 'shipping vat rate negative' => [['shipping_vat_rate' => '-1'], [[]], SourceOrderDefect::VatRateInvalid];
        yield 'quantity zero' => [[], [['quantity' => 0]], SourceOrderDefect::QuantityInvalid];
        yield 'quantity negative' => [[], [['quantity' => -1]], SourceOrderDefect::QuantityInvalid];
        yield 'second line' => [[], [[], ['external_id' => 20, 'quantity' => 0]], SourceOrderDefect::QuantityInvalid];

        yield 'every defect' => [[...$payment, ...$missingRate], [['sku' => '', 'vat_rate' => '-1', 'quantity' => 0]], SourceOrderDefect::PaymentMethodMissing];
        yield 'sku before vat and quantity' => [[], [['quantity' => 0, 'vat_rate' => '-1'], ['external_id' => 20, 'sku' => '']], SourceOrderDefect::SkuMissing];
        yield 'sku before a missing vat rate' => [$missingRate, [['sku' => '', 'quantity' => 0]], SourceOrderDefect::SkuMissing];
        yield 'negative vat before quantity' => [[], [['quantity' => 0], ['external_id' => 20, 'vat_rate' => '-1']], SourceOrderDefect::VatRateInvalid];
        yield 'missing vat before quantity' => [$missingRate, [['quantity' => 0]], SourceOrderDefect::VatRateInvalid];
    }

    /**
     * @param array<string, mixed>       $order
     * @param list<array<string, mixed>> $lines
     */
    #[Test]
    #[DataProvider('defects')]
    public function the_first_defect_replaces_the_order(array $order, array $lines, SourceOrderDefect $expected): void
    {
        $this->insertOrder($order, $lines);

        $source = $this->find();

        self::assertSame($expected, $source->defect);
        self::assertNull($source->order);
    }

    /**
     * @return iterable<string, array{string, string|null, SourceOrderDefect}>
     */
    public static function rowsTheTablesRefuse(): iterable
    {
        yield 'line vat rate missing' => ['PayPal', null, SourceOrderDefect::VatRateInvalid];
        yield 'payment method unknown' => ['Barter', '20', SourceOrderDefect::PaymentMethodMissing];
    }

    #[Test]
    #[DataProvider('rowsTheTablesRefuse')]
    public function a_row_the_source_tables_do_not_hold_today_fails_closed(string $paymentMethod, ?string $vatRate, SourceOrderDefect $expected): void
    {
        $model = new OrderSyncSourceViewModel([
            'reference' => 1,
            'external_id' => 2,
            'order_placed_at' => '2026-11-01 10:00:00+00',
            'lifecycle_status' => 'processing',
            'payment_method_raw' => $paymentMethod,
            'billing_name' => 'Bill Buyer',
            'billing_company' => null,
            'billing_address_line1' => '1 Bill Street',
            'billing_address_line2' => null,
            'billing_address_line3' => null,
            'billing_city' => 'Billville',
            'billing_postcode' => 'BI1 1LL',
            'billing_country' => 'United Kingdom',
            'billing_email' => 'bill@example.test',
            'billing_telephone' => null,
            'delivery_name' => 'Del Buyer',
            'delivery_company' => null,
            'delivery_address_line1' => '2 Del Street',
            'delivery_address_line2' => null,
            'delivery_address_line3' => null,
            'delivery_city' => 'Delville',
            'delivery_postcode' => 'DE1 1LL',
            'delivery_country' => 'Ireland',
            'shipping_method' => null,
            'shipping_total_net' => '0',
            'shipping_vat_rate' => null,
            'comments' => null,
            'transaction_id' => null,
            'lines' => [['sku' => 'A', 'title' => 'A', 'quantity' => 1, 'price' => '1', 'price_vat' => '0', 'vat_rate' => $vatRate]],
        ]);

        $row = $model->toDomain();

        self::assertSame($paymentMethod, $row->paymentMethod);
        self::assertSame($vatRate, $row->lines[0]->vatRate);
        self::assertNull($row->shippingVatRate);
        self::assertSame($expected, SourceOrderTransformer::fromRow($row)->defect);
    }

    #[Test]
    public function one_call_reads_complete_and_defective_orders_and_omits_unknown_references(): void
    {
        $this->insertOrder([], [[]]);
        $this->insertOrder(['reference' => self::OTHER_REFERENCE, 'external_id' => self::OTHER_EXTERNAL_ID, 'payment_method_raw' => null], [[]]);

        $orders = \array_map(SourceOrderTransformer::fromRow(...), $this->repository->findByReferences([self::REFERENCE, self::OTHER_REFERENCE, 2147450699, 2147483648]));

        self::assertEqualsCanonicalizing([self::REFERENCE, self::OTHER_REFERENCE], \array_keys($orders));
        self::assertNotNull($orders[self::REFERENCE]->order);
        self::assertNull($orders[self::REFERENCE]->defect);
        self::assertNull($orders[self::OTHER_REFERENCE]->order);
        self::assertSame(SourceOrderDefect::PaymentMethodMissing, $orders[self::OTHER_REFERENCE]->defect);
        self::assertSame([], $this->repository->findByReferences([]));
    }

    /**
     * @return iterable<string, array{string, string, list<string>, list<string>}>
     */
    public static function placedInRanges(): iterable
    {
        yield 'one summer day, with its first London hour' => [
            '2031-07-15', '2031-07-15',
            ['2031-07-14 23:00:00+00', '2031-07-14 23:30:00+00', '2031-07-15 22:59:59+00'],
            ['2031-07-14 22:59:59+00', '2031-07-15 23:00:00+00', '2031-07-15 23:30:00+00'],
        ];
        yield 'one winter day' => [
            '2031-01-15', '2031-01-15',
            ['2031-01-15 00:00:00+00', '2031-01-15 23:59:59+00'],
            ['2031-01-14 23:59:59+00', '2031-01-16 00:00:00+00'],
        ];
        yield 'across the clocks going forward' => [
            '2031-03-29', '2031-03-30',
            ['2031-03-29 00:00:00+00', '2031-03-30 22:59:59+00'],
            ['2031-03-28 23:59:59+00', '2031-03-30 23:00:00+00'],
        ];
        yield 'across the clocks going back' => [
            '2031-10-25', '2031-10-26',
            ['2031-10-24 23:00:00+00', '2031-10-26 23:59:59+00'],
            ['2031-10-24 22:59:59+00', '2031-10-27 00:00:00+00'],
        ];
    }

    /**
     * @param list<string> $inRange
     * @param list<string> $outOfRange
     */
    #[Test]
    #[DataProvider('placedInRanges')]
    public function the_references_of_a_range_of_london_days_are_counted_and_listed_in_order(string $from, string $to, array $inRange, array $outOfRange): void
    {
        foreach ([...$outOfRange, ...\array_reverse($inRange)] as $i => $placedAt) {
            $this->insertOrder(['reference' => self::REFERENCE - $i, 'external_id' => self::EXTERNAL_ID - $i, 'order_placed_at' => $placedAt], []);
        }
        $first = self::REFERENCE - \count($outOfRange) - \count($inRange) + 1;

        $this->assertPlacedIn(\range($first, $first + \count($inRange) - 1), $from, $to);
    }

    #[Test]
    public function a_duplicate_reference_is_one_reference_and_neither_status_nor_sync_row_matters(): void
    {
        $placedAt = ['order_placed_at' => '2031-07-15 12:00:00+00'];
        $this->insertOrder([...$placedAt, 'reference' => self::OTHER_REFERENCE, 'external_id' => self::OTHER_EXTERNAL_ID], [[]]);
        $this->insertOrder([...$placedAt, 'reference' => self::OTHER_REFERENCE, 'external_id' => self::OTHER_EXTERNAL_ID + 1, 'lifecycle_status' => 'cancelled'], [[]]);
        $this->insertOrder([...$placedAt, 'lifecycle_status' => 'cancelled'], [[]]);
        foreach (['synced', 'failed'] as $i => $status) {
            $reference = self::OTHER_REFERENCE + 1 + $i;
            $this->insertOrder([...$placedAt, 'reference' => $reference, 'external_id' => self::OTHER_EXTERNAL_ID + 2 + $i], [[]]);
            DB::table('quickbooks.order_syncs')->insert(['reference' => $reference, 'order_external_id' => $reference, 'realm_id' => 'realm-1', 'status' => $status]);
        }

        $this->assertPlacedIn([self::REFERENCE, self::OTHER_REFERENCE, self::OTHER_REFERENCE + 1, self::OTHER_REFERENCE + 2], '2031-07-15', '2031-07-15');
    }

    #[Test]
    public function a_range_without_orders_has_no_references(): void
    {
        $this->assertPlacedIn([], '2031-07-15', '2031-07-16');
    }

    /**
     * @param list<int> $expected
     */
    private function assertPlacedIn(array $expected, string $from, string $to): void
    {
        $bounds = OrderSyncCalendarResolver::rangeBounds(new DateTimeImmutable($from), new DateTimeImmutable($to));

        self::assertSame($expected, $this->repository->referencesPlacedIn(...$bounds));
        self::assertSame(\count($expected), $this->repository->countPlacedIn(...$bounds));
    }

    private function find(): SourceOrderDTO
    {
        $orders = $this->repository->findByReferences([self::REFERENCE]);
        self::assertSame([self::REFERENCE], \array_keys($orders));

        return SourceOrderTransformer::fromRow($orders[self::REFERENCE]);
    }

    /**
     * @param array<string, mixed>       $order
     * @param list<array<string, mixed>> $lines
     */
    private function insertOrder(array $order, array $lines): void
    {
        $row = [
            'external_id' => self::EXTERNAL_ID,
            'reference' => self::REFERENCE,
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
            'billing_name' => 'Bill Buyer',
            'billing_email' => 'bill@example.test',
            'billing_address_line1' => '1 Bill Street',
            'billing_city' => 'Billville',
            'billing_postcode' => 'BI1 1LL',
            'billing_country' => 'United Kingdom',
            'delivery_name' => 'Del Buyer',
            'delivery_email' => 'del@example.test',
            'delivery_address_line1' => '2 Del Street',
            'delivery_address_line2' => 'Flat 2',
            'delivery_city' => 'Delville',
            'delivery_postcode' => 'DE1 1LL',
            'delivery_country' => 'Ireland',
            'shipping_charge_net' => '4.990000',
            'payment_method' => 'card',
            'payment_method_raw' => 'Opayo Hosted',
            'order_placed_at' => '2026-11-01 10:00:00+00',
            'created_at' => '2026-11-01 10:00:00+00',
            'updated_at' => '2026-11-01 10:00:00+00',
            ...$order,
        ];
        $orderId = DB::table('shopwired.orders')->insertGetId($row);

        foreach ($lines as $line) {
            DB::table('shopwired.order_products')->insert([
                'order_id' => $orderId,
                'order_external_id' => $row['external_id'],
                'external_id' => 10,
                'title' => 'Line',
                'sku' => 'SKU-10',
                'price' => '12.5',
                'price_vat' => '2.5',
                'total' => '25.0',
                'total_vat' => '5.0',
                'original_price' => '12.5',
                'quantity' => 2,
                'vat_rate' => '20',
                'created_at' => '2026-11-01 10:00:00+00',
                'updated_at' => '2026-11-01 10:00:00+00',
                ...$line,
            ]);
        }
    }
}
