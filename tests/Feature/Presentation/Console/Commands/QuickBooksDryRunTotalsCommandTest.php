<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Console\Commands;

use App\Application\Contracts\QuickBooks\OrderTotalsQueryRepositoryInterface;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\QuickBooks\EloquentOrderTotalsQueryRepository;
use App\Infrastructure\QuickBooks\QuickBooksConfig;
use App\Presentation\Console\Commands\QuickBooksDryRunTotalsCommand;
use DateTimeImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Feature\Concerns\InsertsSourceOrders;
use Tests\TestCase;

#[CoversClass(QuickBooksDryRunTotalsCommand::class)]
#[CoversClass(EloquentOrderTotalsQueryRepository::class)]
final class QuickBooksDryRunTotalsCommandTest extends TestCase
{
    use DatabaseTransactions;
    use InsertsSourceOrders;

    private const string HEADER = 'reference,order_date,expected_total,shopwired_total,difference,result';

    private const string REALM = '9130000000000001';

    private const int FIRST = 890000101;

    private const int SECOND = 890000102;

    private const int THIRD = 890000103;

    private const int FOURTH = 890000104;

    // The inserted order's 25.00 goods and 4.99 shipping, each plus 20% VAT, total 35.99.
    private const array MATCHING = ['tax_value' => '6.000000'];

    /**
     * @return iterable<string, array{string, string, array{string, string, string, string}}> the last instant before, first in, last in and first after
     */
    public static function londonRanges(): iterable
    {
        yield 'summer' => ['2001-06-10', '2001-06-11', ['2001-06-09 22:59:59+00', '2001-06-09 23:00:00+00', '2001-06-11 22:59:59+00', '2001-06-11 23:00:00+00']];
        yield 'winter' => ['2001-01-10', '2001-01-11', ['2001-01-09 23:59:59+00', '2001-01-10 00:00:00+00', '2001-01-11 23:59:59+00', '2001-01-12 00:00:00+00']];
        yield 'across the spring clock change' => ['2001-03-24', '2001-03-25', ['2001-03-23 23:59:59+00', '2001-03-24 00:00:00+00', '2001-03-25 22:59:59+00', '2001-03-25 23:00:00+00']];
        yield 'the BST change fixture' => ['2026-10-24', '2026-10-25', ['2026-10-23 22:30:00+00', '2026-10-23 23:30:00+00', '2026-10-25 23:30:00+00', '2026-10-26 00:30:00+00']];
    }

    /**
     * @param array{string, string, string, string} $placedAt
     */
    #[Test]
    #[DataProvider('londonRanges')]
    public function it_reports_the_london_days_of_the_range_inclusive(string $from, string $to, array $placedAt): void
    {
        DB::table('shopwired.orders')
            ->whereBetween('order_placed_at', ["{$from} 00:00:00+12", "{$to} 23:59:59-12"])
            ->update(['order_placed_at' => '1990-01-01 00:00:00+00']);
        foreach ([self::FIRST, self::SECOND, self::THIRD, self::FOURTH] as $index => $reference) {
            $this->insertOrder($reference, $placedAt[$index], self::MATCHING);
        }

        $tester = $this->report($from, $to);

        $this->assertReport([
            self::SECOND . ",{$from},35.99,35.99,0.00,match",
            self::THIRD . ",{$to},35.99,35.99,0.00,match",
        ], $tester);
    }

    #[Test]
    public function it_leaves_cancelled_orders_out(): void
    {
        $this->insertOrder(self::FIRST, '2001-01-10 10:00:00+00', [...self::MATCHING, 'lifecycle_status' => 'cancelled']);
        $this->insertOrder(self::SECOND, '2001-01-10 11:00:00+00', self::MATCHING);

        $this->assertReport([self::SECOND . ',2001-01-10,35.99,35.99,0.00,match'], $this->report());
    }

    #[Test]
    public function it_reports_an_already_synced_order(): void
    {
        $this->insertOrder(self::FIRST, '2001-01-10 10:00:00+00', self::MATCHING);
        $this->insertSync(['status' => 'synced', 'qbo_txn_id' => '1', 'doc_type' => 'invoice', 'synced_at' => '2001-01-11 10:00:00+00']);

        $this->assertReport([self::FIRST . ',2001-01-10,35.99,35.99,0.00,match'], $this->report());
    }

    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function comparisons(): iterable
    {
        yield 'a penny below' => ['6.010000', '35.99,36.00,-0.01,warning'];
        yield 'a penny above' => ['5.990000', '35.99,35.98,0.01,warning'];
        yield 'null VAT' => [null, '35.99,29.99,6.00,warning'];
    }

    #[Test]
    #[DataProvider('comparisons')]
    public function a_difference_in_either_direction_warns(?string $taxValue, string $expected): void
    {
        $this->insertOrder(self::FIRST, '2001-01-10 10:00:00+00', ['tax_value' => $taxValue]);

        $this->assertReport([self::FIRST . ",2001-01-10,{$expected}"], $this->report());
    }

    #[Test]
    public function the_shopwired_total_is_rounded_once_after_summing(): void
    {
        $this->insertOrder(self::FIRST, '2001-01-10 10:00:00+00', [
            'sub_total_net' => '3.335000',
            'shipping_total_net' => '3.335000',
            'shipping_vat_rate' => '0',
            'tax_value' => '3.334000',
        ]);
        $this->updateLines(['price' => '6.66', 'price_vat' => '0', 'vat_rate' => '0', 'quantity' => 1]);

        $this->assertReport([self::FIRST . ',2001-01-10,10.00,10.00,0.00,match'], $this->report());
    }

    #[Test]
    public function vat_is_rounded_per_line(): void
    {
        $this->insertOrder(self::FIRST, '2001-01-10 10:00:00+00', [
            'sub_total_net' => '0.040000',
            'shipping_total_net' => '0',
            'tax_value' => '0.000000',
        ]);
        $this->updateLines(['price' => '0.02', 'price_vat' => '0.004', 'quantity' => 1]);
        $line = (array) DB::table('shopwired.order_products')->where('order_external_id', self::FIRST)->sole();
        unset($line['id']);
        DB::table('shopwired.order_products')->insert([...$line, 'external_id' => 11]);

        $this->assertReport([self::FIRST . ',2001-01-10,0.04,0.04,0.00,match'], $this->report());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>, string}>
     */
    public static function unbuildableOrders(): iterable
    {
        yield 'no payment method' => [['payment_method_raw' => null], [], 'mapping_missing'];
        yield 'no SKU' => [[], ['sku' => ''], 'item_missing'];
        yield 'no VAT rate' => [['shipping_vat_rate' => null], [], 'tax_rate_unsupported'];
        yield 'unsupported VAT rate' => [[], ['vat_rate' => '5'], 'tax_rate_unsupported'];
        yield 'quantity below one' => [[], ['quantity' => 0], 'qbo_validation'];
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $line
     */
    #[Test]
    #[DataProvider('unbuildableOrders')]
    public function an_order_that_cannot_be_built_has_its_reason_code_and_no_expected_total(array $order, array $line, string $code): void
    {
        $this->insertOrder(self::FIRST, '2001-01-10 10:00:00+00', [...self::MATCHING, ...$order]);
        if ($line !== []) {
            $this->updateLines($line);
        }

        $this->assertReport([self::FIRST . ",2001-01-10,,35.99,,{$code}"], $this->report());
    }

    #[Test]
    public function an_order_without_lines_is_unbuildable(): void
    {
        $this->insertOrder(self::FIRST, '2001-01-10 10:00:00+00', self::MATCHING);
        DB::table('shopwired.order_products')->where('order_external_id', self::FIRST)->delete();

        $this->assertReport([self::FIRST . ',2001-01-10,,35.99,,zero_lines'], $this->report());
    }

    #[Test]
    public function a_stored_unknown_outcome_does_not_hide_the_build_failure(): void
    {
        $this->insertOrder(self::FIRST, '2001-01-10 10:00:00+00', [...self::MATCHING, 'payment_method_raw' => null]);
        $this->insertSync(['status' => 'failed', 'last_error_code' => 'outcome_unknown']);

        $this->assertReport([self::FIRST . ',2001-01-10,,35.99,,mapping_missing'], $this->report());
    }

    #[Test]
    public function warnings_and_unbuildable_orders_exit_zero_and_the_counts_go_to_stderr(): void
    {
        $this->insertOrder(self::FIRST, '2001-01-10 10:00:00+00', self::MATCHING);
        $this->insertOrder(self::SECOND, '2001-01-10 11:00:00+00', self::MATCHING);
        $this->insertOrder(self::THIRD, '2001-01-10 12:00:00+00', ['tax_value' => '6.010000']);
        $this->insertOrder(self::FOURTH, '2001-01-10 13:00:00+00', [...self::MATCHING, 'payment_method_raw' => null]);

        $tester = $this->report();

        $this->assertReport([
            self::FIRST . ',2001-01-10,35.99,35.99,0.00,match',
            self::SECOND . ',2001-01-10,35.99,35.99,0.00,match',
            self::THIRD . ',2001-01-10,35.99,36.00,-0.01,warning',
            self::FOURTH . ',2001-01-10,,35.99,,mapping_missing',
        ], $tester);
        self::assertSame("match: 2\nwarning: 1\nunbuildable: 1\n", $tester->getErrorOutput(true));
    }

    #[Test]
    public function one_warning_and_one_unbuildable_order_exit_zero(): void
    {
        $this->insertOrder(self::FIRST, '2001-01-10 10:00:00+00');
        $this->insertOrder(self::SECOND, '2001-01-10 11:00:00+00', [...self::MATCHING, 'payment_method_raw' => null]);

        $tester = $this->report();

        $this->assertReport([
            self::FIRST . ',2001-01-10,35.99,29.99,6.00,warning',
            self::SECOND . ',2001-01-10,,35.99,,mapping_missing',
        ], $tester);
        self::assertSame("match: 0\nwarning: 1\nunbuildable: 1\n", $tester->getErrorOutput(true));
    }

    #[Test]
    public function it_calls_no_quickbooks_and_writes_no_row(): void
    {
        $this->insertOrder(self::FIRST, '2001-01-10 10:00:00+00', self::MATCHING);
        $this->insertOrder(self::SECOND, '2001-01-10 11:00:00+00', [...self::MATCHING, 'payment_method_raw' => null]);
        Http::fake();
        $statements = [];
        DB::listen(static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = \mb_strtolower(\strtok($query->sql, ' ') ?: '');
        });

        self::assertSame(0, $this->report()->getStatusCode());

        Http::assertNothingSent();
        // intentional: every QBO call needs the config, and the SDK's calls bypass Http::fake().
        self::assertFalse($this->app->resolved(QuickBooksConfig::class));
        self::assertSame(['select'], \array_values(\array_unique($statements)));
    }

    #[Test]
    public function it_needs_no_stored_connection(): void
    {
        DB::table('public.quickbooks_connections')->delete();

        $this->assertReportsOneOrder();
    }

    #[Test]
    public function it_needs_no_credentials(): void
    {
        \config([
            'quickbooks.environment' => '',
            'quickbooks.client_id' => '',
            'quickbooks.client_secret' => '',
            'quickbooks.redirect_uri' => '',
            'quickbooks.realm_id' => '',
        ]);
        $this->app->forgetInstance(QuickBooksConfig::class);

        $this->assertReportsOneOrder();

        $this->expectException(InvalidConfigurationException::class);
        $this->app->make(QuickBooksConfig::class);
    }

    #[Test]
    public function it_ignores_a_floor_inside_the_range(): void
    {
        \config(['quickbooks.backfill_floor_date' => '2001-01-11']);
        foreach ([self::FIRST => '10', self::SECOND => '11', self::THIRD => '12'] as $reference => $day) {
            $this->insertOrder($reference, "2001-01-{$day} 10:00:00+00", self::MATCHING);
        }

        $this->assertReport([
            self::FIRST . ',2001-01-10,35.99,35.99,0.00,match',
            self::SECOND . ',2001-01-11,35.99,35.99,0.00,match',
            self::THIRD . ',2001-01-12,35.99,35.99,0.00,match',
        ], $this->report());
    }

    #[Test]
    public function it_lists_every_order_with_the_floor_after_the_range(): void
    {
        \config(['quickbooks.backfill_floor_date' => '2001-02-01']);
        foreach ([self::FIRST => '10', self::SECOND => '11', self::THIRD => '12'] as $reference => $day) {
            $this->insertOrder($reference, "2001-01-{$day} 10:00:00+00", self::MATCHING);
        }

        $this->assertReport([
            self::FIRST . ',2001-01-10,35.99,35.99,0.00,match',
            self::SECOND . ',2001-01-11,35.99,35.99,0.00,match',
            self::THIRD . ',2001-01-12,35.99,35.99,0.00,match',
        ], $this->report());
    }

    #[Test]
    public function it_needs_no_floor(): void
    {
        \config(['quickbooks.backfill_floor_date' => null]);

        $this->assertReportsOneOrder();
    }

    #[Test]
    public function it_reports_a_range_of_thirty_two_days(): void
    {
        $this->insertOrder(self::FIRST, '2001-01-01 10:00:00+00', self::MATCHING);
        $this->insertOrder(self::SECOND, '2001-02-01 10:00:00+00', self::MATCHING);

        $this->assertReport([
            self::FIRST . ',2001-01-01,35.99,35.99,0.00,match',
            self::SECOND . ',2001-02-01,35.99,35.99,0.00,match',
        ], $this->report('2001-01-01', '2001-02-01'));
    }

    #[Test]
    public function it_allows_a_future_end_date(): void
    {
        $this->travelTo(new DateTimeImmutable('2001-01-10 12:00:00+00'));

        $this->assertReportsOneOrder();
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function refusedRanges(): iterable
    {
        yield 'no from' => [['--to' => '2001-01-12']];
        yield 'no to' => [['--from' => '2001-01-10']];
        yield 'empty from' => [['--from' => '', '--to' => '2001-01-12']];
        yield 'unreal from' => [['--from' => '2001-02-30', '--to' => '2001-03-12']];
        yield 'unreal to' => [['--from' => '2001-01-10', '--to' => '2001-02-30']];
        yield 'other format' => [['--from' => '10/01/2001', '--to' => '2001-01-12']];
        yield 'unpadded' => [['--from' => '2001-1-1', '--to' => '2001-01-12']];
        yield 'to before from' => [['--from' => '2001-01-12', '--to' => '2001-01-11']];
    }

    /**
     * @param array<string, string> $options
     */
    #[Test]
    #[DataProvider('refusedRanges')]
    public function a_refused_range_exits_non_zero_with_no_csv(array $options): void
    {
        $tester = $this->runCommand($options);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('Check: --', $tester->getErrorOutput());
    }

    #[Test]
    public function a_failed_read_exits_non_zero_with_no_csv(): void
    {
        $totals = Mockery::mock(OrderTotalsQueryRepositoryInterface::class);
        $totals->shouldReceive('placedIn')->andThrow(new DatabaseOperationFailedException('placedIn', 'connection lost'));
        $this->app->instance(OrderTotalsQueryRepositoryInterface::class, $totals);

        $tester = $this->report();

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refundedStatuses(): iterable
    {
        yield 'refunded' => ['refunded'];
        yield 'part refunded' => ['part_refunded'];
    }

    #[Test]
    #[DataProvider('refundedStatuses')]
    public function a_refund_that_lowered_the_total_still_matches(string $status): void
    {
        $this->insertOrder(self::FIRST, '2001-01-10 10:00:00+00', [...self::MATCHING, 'lifecycle_status' => $status, 'total' => '1.000000']);

        $this->assertReport([self::FIRST . ',2001-01-10,35.99,35.99,0.00,match'], $this->report());
    }

    private function assertReportsOneOrder(): void
    {
        $this->insertOrder(self::FIRST, '2001-01-10 10:00:00+00', self::MATCHING);

        $this->assertReport([self::FIRST . ',2001-01-10,35.99,35.99,0.00,match'], $this->report());
    }

    /**
     * @param list<string> $rows
     */
    private function assertReport(array $rows, CommandTester $tester): void
    {
        self::assertSame(\implode("\n", [self::HEADER, ...$rows, '']), $tester->getDisplay(true));
        self::assertSame(0, $tester->getStatusCode());
    }

    private function report(string $from = '2001-01-10', string $to = '2001-01-12'): CommandTester
    {
        return $this->runCommand(['--from' => $from, '--to' => $to]);
    }

    /**
     * @param array<string, string> $options
     */
    private function runCommand(array $options): CommandTester
    {
        $command = $this->app->make(QuickBooksDryRunTotalsCommand::class);
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $tester->execute($options, ['capture_stderr_separately' => true]);

        return $tester;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function updateLines(array $values): void
    {
        DB::table('shopwired.order_products')->where('order_external_id', self::FIRST)->update($values);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function insertSync(array $state): void
    {
        DB::table('quickbooks.order_syncs')->insert([
            'realm_id' => self::REALM,
            'reference' => self::FIRST,
            'order_external_id' => self::FIRST,
            ...$state,
        ]);
    }
}
