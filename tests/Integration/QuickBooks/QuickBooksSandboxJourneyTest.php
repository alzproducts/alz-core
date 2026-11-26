<?php

declare(strict_types=1);

namespace Tests\Integration\QuickBooks;

use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Shared\ValueObjects\Deadline;
use App\Infrastructure\QuickBooks\QuickBooksTransport;
use Carbon\CarbonImmutable;
use Database\Seeders\OrderSlice\EdgeCase;
use Database\Seeders\OrderSlice\EdgeCaseOrder;
use Database\Seeders\OrderSlice\EdgeCaseOrders;
use Database\Seeders\OrderSlice\ExpectedOutcome;
use Database\Seeders\ProductSlice\SeedIds;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\AuthenticatesAsApprovedUser;
use Tests\Feature\Concerns\InsertsSourceOrders;
use Tests\TestCase;

/**
 * Syncs the orders `dev:seed-fake` seeds into the sandbox through the two endpoints, then reads the documents back.
 * A live run posts over 200 documents.
 *
 * Runs in the developer's database with no transaction: the connection, mappings and cached token there are the real
 * sandbox ones, and a rollback would lose a rotated refresh token.
 */
#[CoversNothing]
#[Group('integration')]
#[Group('quickbooks-sandbox')]
final class QuickBooksSandboxJourneyTest extends TestCase
{
    use AuthenticatesAsApprovedUser;
    use InsertsSourceOrders;

    private const string FROM = '2026-10-01';

    private const string TO = '2026-10-31';

    private const int FIRST_INSERTED_REFERENCE = 2_140_000_000;

    private const int RUNS = 3_000_000;

    private const string SHIPPING_ITEM = 'SHIPPING_ITEM_ID';

    private const array STANDARD_GOODS = [['19.99', 1, 'tax.standard'], ['9.00', 2, 'tax.standard']];

    private const array STANDARD_SHIPPING = ['4.99', 'tax.standard'];

    private const string SHARED_CUSTOMER = 'Jordan Blake, LS1 4AP';

    private string $prefix;

    private string $suffix;

    /** @var list<int> */
    private array $inserted = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        if (\getenv('QUICKBOOKS_SANDBOX_JOURNEY') !== '1') {
            self::markTestSkipped('The sandbox journey runs only with QUICKBOOKS_SANDBOX_JOURNEY=1');
        }
        // intentional: checked before any QuickBooks service is built, so a production realm is never called
        if (\config('quickbooks.environment') !== 'sandbox') {
            self::fail('Refusing to run: QUICKBOOKS_ENVIRONMENT is not sandbox');
        }
        $floor = (string) \config('quickbooks.backfill_floor_date');
        if ($floor === '' || $floor > self::FROM) {
            self::fail('Missing: QUICKBOOKS_BACKFILL_FLOOR_DATE at or before ' . self::FROM);
        }

        $run = \mb_strtoupper(\bin2hex(\random_bytes(4)));
        $this->prefix = "J{$run}";
        $this->suffix = " J{$run}";
        \config(['quickbooks.doc_number_prefix' => $this->prefix, 'quickbooks.customer_name_suffix' => $this->suffix]);
        // intentional: the OAuth token refresh goes through the Http client
        Http::allowStrayRequests();
    }

    #[Override]
    protected function tearDown(): void
    {
        try {
            $this->deleteInsertedOrders();
        } finally {
            parent::tearDown();
        }
    }

    #[Test]
    public function the_synthetic_orders_sync_end_to_end(): void
    {
        $statuses = self::syntheticOrders();
        $references = \array_keys($statuses);
        self::assertNoOtherOrderInRange($references);
        DB::table('quickbooks.order_syncs')->whereIn('reference', $references)->delete();

        $this->syncRange();

        $this->assertOutcomes($statuses);
        $this->assertOneSharedCustomer();
        $documents = $this->documents();

        $this->syncRange();
        self::assertSame(\count($documents), \count($this->documents()), 'The second range sync changed the number of documents');
        self::report('documents', \count($documents) . ' before and after the second range sync');

        $this->assertReferenceSyncFindsTheFirstDocument(self::edgeOrder(EdgeCase::SalesReceiptWithoutCompany)->order->reference);

        [$ireland, $penny] = $this->insertOrders();
        $this->syncReferences([$ireland, $penny]);

        $documents = $this->documents();
        $this->assertNamedDocuments($documents, $ireland, $penny);
        self::reportTotalDifferences($documents, [...$references, $ireland, $penny]);
    }

    private function syncRange(): void
    {
        $this->asApprovedUser()
            ->postJson('/api/quickbooks/orders/sync-range', ['from' => self::FROM, 'to' => self::TO])
            ->assertAccepted();
    }

    /**
     * @param list<int> $references
     */
    private function syncReferences(array $references): void
    {
        $this->asApprovedUser()
            ->postJson('/api/quickbooks/orders/sync', ['references' => $references])
            ->assertAccepted();
    }

    /**
     * @return array<int, string> the status of each order `dev:seed-fake` seeds, by reference
     */
    private static function syntheticOrders(): array
    {
        $statuses = DB::table('shopwired.orders_deduplicated')
            ->whereBetween('reference', [SeedIds::ORDER_REFERENCE_BASE, SeedIds::ORDER_REFERENCE_MAX])
            ->whereBetween('external_id', [SeedIds::ORDER_BASE, SeedIds::ORDER_LINE_BASE - 1])
            ->whereBetween('order_placed_at', [self::FROM . ' 00:00:00+00', self::TO . ' 23:59:59+00'])
            ->orderBy('reference')
            ->pluck('status_type', 'reference')
            ->all();
        $missing = \array_filter(
            EdgeCaseOrders::all(),
            static fn(EdgeCaseOrder $edge): bool => !isset($statuses[$edge->order->reference]),
        );
        self::assertSame([], $missing, 'Missing: the seeded orders (php artisan dev:seed-fake)');

        return $statuses;
    }

    /**
     * The range sync posts every order placed in it, so another order's customer would reach the sandbox.
     *
     * @param list<int> $references
     */
    private static function assertNoOtherOrderInRange(array $references): void
    {
        $others = DB::table('shopwired.orders_deduplicated')
            ->where('order_placed_at', '>=', self::FROM . ' 00:00:00 Europe/London')
            ->where('order_placed_at', '<', '2026-11-01 00:00:00 Europe/London')
            ->whereNotIn('reference', $references)
            ->count();

        self::assertSame(0, $others, 'Refusing to run: the range holds orders dev:seed-fake did not seed');
    }

    private static function edgeOrder(EdgeCase $case): EdgeCaseOrder
    {
        return \array_find(EdgeCaseOrders::all(), static fn(EdgeCaseOrder $edge): bool => $edge->case === $case)
            ?? self::fail("No edge-case order for {$case->value}");
    }

    /**
     * @param array<int, string> $statuses
     */
    private function assertOutcomes(array $statuses): void
    {
        $expected = [];
        foreach ($statuses as $reference => $status) {
            if ($status !== 'Cancelled') {
                $expected[$reference] = 'synced';
            }
        }
        foreach (EdgeCaseOrders::all() as $edge) {
            $reference = $edge->order->reference;
            if ($edge->expectedOutcome === ExpectedOutcome::SkippedCancelled) {
                self::assertSame('Cancelled', $statuses[$reference]);
            } else {
                $expected[$reference] = $edge->expectedOutcome->value;
            }
        }

        $actual = [];
        $rows = DB::table('quickbooks.order_syncs')->whereIn('reference', \array_keys($statuses))->orderBy('reference')->get();
        foreach ($rows as $row) {
            self::assertArrayNotHasKey($row->reference, $actual, 'An order has two sync rows');
            $actual[$row->reference] = $row->last_error_code === null ? $row->status : "{$row->status}:{$row->last_error_code}";
        }
        \ksort($expected);

        self::assertSame($expected, $actual);
        self::report('outcomes', [
            ...\array_count_values($actual),
            'cancelled, no row' => \count($statuses) - \count($actual),
        ]);
    }

    private function assertOneSharedCustomer(): void
    {
        $name = self::SHARED_CUSTOMER . $this->suffix;

        self::assertCount(1, $this->sandboxRows("SELECT * FROM Customer WHERE DisplayName = '{$name}'"));
        self::report('shared customer', "one named {$name}");
    }

    private function assertReferenceSyncFindsTheFirstDocument(int $reference): void
    {
        $row = DB::table('quickbooks.order_syncs')->where('reference', $reference)->sole();
        self::assertSame('synced', $row->status);
        self::assertNotNull($row->qbo_txn_id);
        DB::table('quickbooks.order_syncs')->where('id', $row->id)->delete();

        $this->syncReferences([$reference]);

        $again = DB::table('quickbooks.order_syncs')->where('reference', $reference)->sole();
        self::assertNotSame($row->id, $again->id);
        self::assertSame(['synced', $row->qbo_txn_id], [$again->status, $again->qbo_txn_id]);
        $matches = $this->documentsFor($this->documents(), $reference);
        self::assertCount(1, $matches);
        self::assertSame($row->qbo_txn_id, self::text($matches[0]['row'], 'Id'));
        self::report('reference sync', "order {$reference} kept its first document");
    }

    /**
     * @return array{int, int} the zero-rated Ireland and penny lines references
     */
    private function insertOrders(): array
    {
        $ireland = self::FIRST_INSERTED_REFERENCE + \random_int(0, self::RUNS - 1) * 2;
        $penny = $ireland + 1;
        $references = [$ireland, $penny];
        // intentional: an existing row must never reach the cleanup's references
        $taken = DB::table('shopwired.orders')->whereIn('external_id', $references)->orWhereIn('reference', $references)->exists()
            || DB::table('shopwired.order_products')->whereIn('order_external_id', $references)->exists()
            || DB::table('quickbooks.order_syncs')->whereIn('reference', $references)->exists();
        self::assertFalse($taken, 'The chosen references are taken; run again');
        $this->inserted = $references;

        $this->insertOrder($ireland, state: [
            'billing_name' => 'Sync Ireland',
            'billing_postcode' => 'D02 X285',
            'billing_country' => 'Ireland',
            'delivery_postcode' => 'D02 X285',
            'delivery_country' => 'Ireland',
            'shipping_vat_rate' => '0',
            'tax_value' => '0',
        ], sku: 'FIXTURE-SKU-001');
        $this->line($ireland)->update(['price_vat' => '0', 'total_vat' => '0', 'vat_rate' => '0']);

        $this->insertOrder($penny, state: [
            'billing_name' => 'Sync Penny',
            'billing_postcode' => 'LS1 4AP',
            'total' => '0.05',
            'sub_total_net' => '0.04',
            'shipping_total_net' => '0',
            'shipping_charge_net' => '0',
            'tax_value' => '0.008',
        ], sku: 'FIXTURE-SKU-001');
        $line = ['price' => '0.02', 'price_vat' => '0.004', 'total' => '0.02', 'total_vat' => '0.004', 'original_price' => '0.02', 'quantity' => 1];
        $this->line($penny)->update($line);
        $second = (array) $this->line($penny)->sole();
        unset($second['id']);
        DB::table('shopwired.order_products')->insert([...$second, 'external_id' => 11, 'sku' => 'FIXTURE-SKU-002']);

        return [$ireland, $penny];
    }

    private function line(int $reference): Builder
    {
        return DB::table('shopwired.order_products')->where('order_external_id', $reference);
    }

    private function deleteInsertedOrders(): void
    {
        if ($this->inserted === []) {
            return;
        }

        DB::table('quickbooks.order_syncs')->whereIn('reference', $this->inserted)->delete();
        DB::table('shopwired.order_products')->whereIn('order_external_id', $this->inserted)->delete();
        DB::table('shopwired.orders')->whereIn('external_id', $this->inserted)->delete();
        $this->inserted = [];
    }

    /**
     * Net literals: goods as amount, quantity and tax key; shipping as amount and tax key; then the accepted tax and total pairs.
     *
     * @return array<string, array{int, string, string, list<array{string, int, string}>, array{string, string}|null, list<array{string, string}>}>
     */
    private static function namedDocuments(int $ireland, int $penny): array
    {
        $reference = static fn(EdgeCase $case): int => self::edgeOrder($case)->order->reference;
        $goods = self::STANDARD_GOODS;
        $shipping = self::STANDARD_SHIPPING;
        $jordan = self::SHARED_CUSTOMER;

        return [
            'InvoiceWithCompany' => [$reference(EdgeCase::InvoiceWithCompany), 'Invoice', 'Hartley Care Homes Ltd', $goods, $shipping, [['6.80', '40.78']]],
            'SalesReceiptWithoutCompany' => [$reference(EdgeCase::SalesReceiptWithoutCompany), 'SalesReceipt', 'Priya Shah, LS1 4AP', $goods, $shipping, [['6.80', '40.78']]],
            'CountryIreland' => [$reference(EdgeCase::CountryIreland), 'SalesReceipt', 'Jordan Blake, D02 X285', $goods, $shipping, [['6.80', '40.78']]],
            'ZeroRated' => [$reference(EdgeCase::ZeroRated), 'SalesReceipt', $jordan, [['25.00', 2, 'tax.zero']], ['4.99', 'tax.zero'], [['0.00', '29.99']]],
            'VatRelief' => [$reference(EdgeCase::VatRelief), 'SalesReceipt', $jordan, [['45.00', 1, 'tax.zero'], ['9.99', 1, 'tax.standard']], $shipping, [['3.00', '62.98']]],
            'MultiQuantitySevenDp' => [$reference(EdgeCase::MultiQuantitySevenDp), 'SalesReceipt', $jordan, [['25.00', 3, 'tax.standard']], $shipping, [['6.00', '35.99']]],
            'DiscountedShipping' => [$reference(EdgeCase::DiscountedShipping), 'SalesReceipt', $jordan, $goods, ['3.00', 'tax.standard'], [['6.40', '38.39']]],
            'ShippingThirdDecimalFive' => [$reference(EdgeCase::ShippingThirdDecimalFive), 'SalesReceipt', $jordan, $goods, ['4.13', 'tax.standard'], [['6.63', '39.75'], ['6.62', '39.74']]],
            'SkuOverride' => [$reference(EdgeCase::SkuOverride), 'SalesReceipt', $jordan, [['14.99', 1, 'tax.standard']], $shipping, [['4.00', '23.98']]],
            'Zero-rated Ireland' => [$ireland, 'SalesReceipt', 'Sync Ireland, D02 X285', [['25.00', 2, 'tax.eu_goods']], ['4.99', 'tax.eu_shipping'], [['0.00', '29.99']]],
            'Penny lines' => [$penny, 'SalesReceipt', 'Sync Penny, LS1 4AP', [['0.02', 1, 'tax.standard'], ['0.02', 1, 'tax.standard']], null, [['0.00', '0.04'], ['0.01', '0.05']]],
        ];
    }

    /**
     * @param list<array{type: string, row: object}> $documents
     */
    private function assertNamedDocuments(array $documents, int $ireland, int $penny): void
    {
        $taxCodes = DB::table('quickbooks.mappings')->where('key', 'like', 'tax.%')->pluck('qbo_ref_id', 'key')->all();
        foreach (self::namedDocuments($ireland, $penny) as $case => [$reference, $type, $customer, $goods, $shipping, $taxAndTotals]) {
            $matches = $this->documentsFor($documents, $reference);
            self::assertCount(1, $matches, "{$case}: not one document");
            $row = $matches[0]['row'];
            $lines = self::lines($row);
            $expected = \array_map(static fn(array $line): array => [$line[0], $line[1], $taxCodes[$line[2]]], $goods);

            self::assertSame($type, $matches[0]['type'], "{$case}: type");
            self::assertSame($customer . $this->suffix, $this->nameOf('Customer', self::text($row, 'CustomerRef'), 'DisplayName'), "{$case}: customer");
            self::assertSame($expected, \array_map(
                static fn(array $line): array => [$line['amount'], $line['quantity'], $line['taxCode']],
                $lines['goods'],
            ), "{$case}: goods lines");
            self::assertSame(
                $shipping === null ? [] : [[$shipping[0], $taxCodes[$shipping[1]]]],
                \array_map(static fn(array $line): array => [$line['amount'], $line['taxCode']], $lines['shipping']),
                "{$case}: shipping line",
            );
            if ($case === 'SkuOverride') {
                self::assertSame('FIXTURE-SKU-007', $this->nameOf('Item', $lines['goods'][0]['item'], 'Name'), "{$case}: item");
            }
            $taxAndTotal = [self::money(self::text(self::field($row, 'TxnTaxDetail'), 'TotalTax') ?? '0'), self::money(self::text($row, 'TotalAmt'))];
            self::assertContains($taxAndTotal, $taxAndTotals, "{$case}: tax and total " . \implode(', ', $taxAndTotal));
            self::report('named document', ['case' => $case, 'type' => $type, 'customer' => $customer . $this->suffix, 'lines' => $lines, 'tax and total' => $taxAndTotal]);
        }
    }

    /**
     * @param list<array{type: string, row: object}> $documents
     * @param list<int> $references
     */
    private static function reportTotalDifferences(array $documents, array $references): void
    {
        // intentional: orders.total falls with refunds, so the ShopWired total is summed from its parts
        $totals = DB::table('shopwired.orders_deduplicated')
            ->whereIn('reference', $references)
            ->selectRaw('reference, round(sub_total_net + shipping_total_net + coalesce(tax_value, 0), 2)::text as total')
            ->pluck('total', 'reference')
            ->all();
        $differences = [];
        foreach ($documents as ['row' => $row]) {
            $docNumber = (string) self::text($row, 'DocNumber');
            $reference = (int) \mb_substr($docNumber, (int) \mb_strrpos($docNumber, 'A') + 1);
            self::assertArrayHasKey($reference, $totals, "Document {$docNumber} belongs to no order of this run");
            $total = self::money(self::text($row, 'TotalAmt'));
            if ($total !== $totals[$reference]) {
                $differences[] = ['reference' => $reference, 'quickbooks' => $total, 'shopwired' => $totals[$reference]];
            }
        }

        self::report('total differences', \count($differences) . ' of ' . \count($documents) . ' documents');
        foreach ($differences as $difference) {
            self::report('total difference', $difference);
        }
    }

    /**
     * @return list<array{type: string, row: object}> every sandbox document carrying this run's prefix
     */
    private function documents(): array
    {
        $documents = [];
        foreach (['Invoice', 'SalesReceipt'] as $type) {
            $rows = $this->sandboxRows("SELECT * FROM {$type} WHERE DocNumber LIKE '{$this->prefix}%' MAXRESULTS 1000");
            self::assertLessThan(1000, \count($rows), "{$type} documents reached the query limit");
            foreach ($rows as $row) {
                $documents[] = ['type' => $type, 'row' => $row];
            }
        }

        return $documents;
    }

    /**
     * @param list<array{type: string, row: object}> $documents
     *
     * @return list<array{type: string, row: object}>
     */
    private function documentsFor(array $documents, int $reference): array
    {
        return \array_values(\array_filter(
            $documents,
            fn(array $document): bool => self::text($document['row'], 'DocNumber') === "{$this->prefix}A{$reference}",
        ));
    }

    /**
     * @return array{goods: list<array<string, mixed>>, shipping: list<array<string, mixed>>}
     */
    private static function lines(object $document): array
    {
        $lines = ['goods' => [], 'shipping' => []];
        $rows = self::field($document, 'Line');
        foreach (\is_array($rows) ? $rows : [$rows] as $row) {
            $detail = self::field($row, 'SalesItemLineDetail');
            if (!\is_object($detail)) {
                self::assertSame('SubTotalLineDetail', self::text($row, 'DetailType'), 'An unexpected kind of line');

                continue;
            }
            $item = self::text($detail, 'ItemRef');
            $lines[$item === self::SHIPPING_ITEM ? 'shipping' : 'goods'][] = [
                'item' => $item,
                'amount' => self::money(self::text($row, 'Amount')),
                'quantity' => (int) self::text($detail, 'Qty'),
                'taxCode' => self::text($detail, 'TaxCodeRef'),
            ];
        }

        return $lines;
    }

    private function nameOf(string $entity, ?string $id, string $field): ?string
    {
        return self::text($this->sandboxRows("SELECT * FROM {$entity} WHERE Id = '{$id}'")[0] ?? null, $field);
    }

    /**
     * @return list<object>
     */
    private function sandboxRows(string $query): array
    {
        return $this->app->make(QuickBooksTransport::class)->query(
            $this->app->make(QuickBooksTokenService::class)->accessToken(),
            $query,
            Deadline::after(CarbonImmutable::now(), 60),
        );
    }

    private static function field(mixed $row, string $field): mixed
    {
        return \is_object($row) ? \get_object_vars($row)[$field] ?? null : null;
    }

    private static function text(mixed $row, string $field): ?string
    {
        $value = self::field($row, $field);
        $value = \is_object($value) ? self::field($value, 'value') : $value;

        return \is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private static function money(?string $amount): string
    {
        self::assertIsNumeric($amount);

        return \number_format((float) $amount, 2, '.', '');
    }

    private static function report(string $label, mixed $value): void
    {
        \fwrite(\STDERR, "\n[journey] {$label}: " . \json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
    }
}
