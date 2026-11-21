<?php

declare(strict_types=1);

namespace Tests\Integration\Presentation\Console\Commands;

use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
use App\Application\Enums\FixtureSku;
use App\Application\Shopwired\Enums\OrderQueryMode;
use App\Domain\Catalog\Order\ValueObjects\OrderStatusType;
use App\Domain\Catalog\Order\ValueObjects\RawPaymentMethod;
use App\Presentation\Console\Commands\Dev\SeedFakeDataCommand;
use Database\Factories\ProductSlice\CategoryRowFactory;
use Database\Seeders\OrderSlice\Customer;
use Database\Seeders\OrderSlice\EdgeCase;
use Database\Seeders\OrderSlice\EdgeCaseOrder;
use Database\Seeders\OrderSlice\EdgeCaseOrders;
use Database\Seeders\OrderSlice\LineSpec;
use Database\Seeders\OrderSlice\OrderMonth;
use Database\Seeders\OrderSlice\OrderProfile;
use Database\Seeders\OrderSlice\OrderRowBuilder;
use Database\Seeders\OrderSlice\OrderSliceGenerator;
use Database\Seeders\OrderSlice\OrderSliceSeeder;
use Database\Seeders\OrderSlice\OrderSliceTables;
use Database\Seeders\OrderSlice\OrderSpec;
use Database\Seeders\ProductSlice\Builders\SeedRandom;
use Database\Seeders\ProductSlice\Contracts\SliceGeneratorInterface;
use Database\Seeders\ProductSlice\SeedProfile;
use Database\Seeders\ProductSlice\SliceRows;
use Database\Seeders\ProductSlice\SliceTables;
use DateTimeImmutable;
use Faker\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * `dev:seed-fake`'s orders slice against the local database, inside a rolled-back transaction, with a one-row
 * products stub (AC1, AC4–AC7, AC12, AC13, AC16, AC17). Serial and outside the default suite: `--fresh` TRUNCATEs
 * the shared orders-slice tables.
 */
#[CoversClass(SeedFakeDataCommand::class)]
#[Group('seed-command')]
final class SeedFakeDataOrdersTest extends TestCase
{
    private const int SALE_CATEGORY_ID = 2_000_000_001;

    private const int FOREIGN_EXTERNAL_ID = 7;

    private const string PROFILE_PATH = 'examples/seed-profile/orders-profile.json';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        DB::beginTransaction();
        Queue::fake();
        Http::preventStrayRequests();

        $connection = \config()->string('database.default');
        \config([
            "database.connections.{$connection}.host" => '127.0.0.1',
            'shopwired.sale_category_id' => self::SALE_CATEGORY_ID,
        ]);
        $this->app->instance(SliceGeneratorInterface::class, $this->productsStub());
    }

    #[Override]
    protected function tearDown(): void
    {
        DB::rollBack();

        parent::tearDown();
    }

    #[Test]
    public function orders_deduplicated_returns_one_row_per_edge_case_reference(): void
    {
        $this->seedFresh();

        $references = \array_values(\array_unique(\array_map(static fn(EdgeCaseOrder $order): int => $order->order->reference, EdgeCaseOrders::all())));
        $counts = DB::table('shopwired.orders_deduplicated')
            ->whereIn('reference', $references)
            ->selectRaw('reference, count(*) as rows')
            ->groupBy('reference')
            ->pluck('rows', 'reference')
            ->all();

        $this->assertCount(\count($references), $counts);
        $this->assertSame([1], \array_values(\array_unique(\array_map('intval', $counts))));
    }

    #[Test]
    public function refunds_lower_only_the_order_total(): void
    {
        $this->seedFresh();

        foreach ([EdgeCase::Refunded, EdgeCase::PartRefunded] as $case) {
            $spec = self::edgeCase($case)->order;
            $order = DB::table(OrderSliceTables::ORDERS)->where('external_id', $spec->externalId)->first();
            $this->assertNotNull($order);

            $refunded = (float) DB::table(OrderSliceTables::ORDER_REFUNDS)->where('order_external_id', $spec->externalId)->sum('value');
            $baseline = (float) $order->sub_total_net + (float) $order->shipping_total_net + (float) $order->tax_value;
            $lines = DB::table(OrderSliceTables::ORDER_PRODUCTS)->where('order_external_id', $spec->externalId)->get();

            if ($case === EdgeCase::Refunded) {
                $this->assertGreaterThanOrEqual(\round($baseline, 3), \round($refunded, 3));
            } else {
                $this->assertGreaterThan(0.0, $refunded);
                $this->assertLessThan($baseline, $refunded);
            }

            $this->assertEqualsWithDelta($baseline - $refunded, (float) $order->total, 0.0005, $case->value);
            $this->assertEqualsWithDelta(
                (float) $order->sub_total_net,
                $lines->sum(static fn(object $line): float => (float) $line->total),
                0.0005,
            );
            $this->assertCount(\count($spec->lines), $lines);
            $this->assertSame((float) $spec->shippingNet, (float) $order->shipping_total_net);
        }
    }

    #[Test]
    public function the_edited_order_keeps_its_cancelled_original_out_of_orders_deduplicated(): void
    {
        $this->seedFresh();

        $edited = self::edgeCase(EdgeCase::Edited);
        $this->assertNotNull($edited->superseded);

        $rows = DB::table(OrderSliceTables::ORDERS)->where('reference', $edited->order->reference)->orderBy('external_id')->get();
        $this->assertCount(2, $rows);
        $this->assertNotSame($rows[0]->external_id, $rows[1]->external_id);
        $this->assertSame('cancelled', $rows[0]->lifecycle_status);
        $this->assertNotSame('cancelled', $rows[1]->lifecycle_status);

        $this->assertSame(
            [$edited->order->externalId],
            DB::table('shopwired.orders_deduplicated')->where('reference', $edited->order->reference)->pluck('external_id')->map('intval')->all(),
        );
    }

    #[Test]
    public function the_sku_override_resolves_to_a_pool_sku_and_the_raw_sku_is_outside_the_pool(): void
    {
        $this->seedFresh();

        $externalId = self::edgeCase(EdgeCase::SkuOverride)->order->externalId;
        $override = DB::table(OrderSliceTables::ORDER_PRODUCT_EXTRA_DATA)->where('order_external_id', $externalId)->value('sku_override');
        $raw = DB::table(OrderSliceTables::ORDER_PRODUCTS)->where('order_external_id', $externalId)->value('sku');
        $resolved = DB::table('shopwired.order_products_resolved')->where('order_external_id', $externalId)->value('sku');

        $this->assertSame($override, $resolved);
        $this->assertContains($resolved, FixtureSku::values());
        $this->assertNotContains($raw, FixtureSku::values());
    }

    #[Test]
    public function the_zero_line_order_has_no_lines_and_no_shipping(): void
    {
        $this->seedFresh();

        $externalId = self::edgeCase(EdgeCase::ZeroLines)->order->externalId;

        $this->assertSame(0, DB::table(OrderSliceTables::ORDER_PRODUCTS)->where('order_external_id', $externalId)->count());
        $this->assertSame(0.0, (float) DB::table(OrderSliceTables::ORDERS)->where('external_id', $externalId)->value('shipping_total_net'));
    }

    #[Test]
    public function every_seeded_order_carries_a_raw_payment_method_and_its_grouping(): void
    {
        $this->seedFresh();

        $orders = DB::table(OrderSliceTables::ORDERS)->get(['payment_method', 'payment_method_raw']);
        $this->assertCount(self::generatedCount(OrderSliceTables::ORDERS), $orders);

        foreach ($orders as $order) {
            $this->assertIsString($order->payment_method_raw);
            $this->assertSame(RawPaymentMethod::from($order->payment_method_raw)->toPaymentMethod()->value, $order->payment_method);
        }
    }

    #[Test]
    public function every_seeded_order_maps_through_the_order_repository(): void
    {
        $this->seedFresh();

        $orders = $this->app->make(OrderRepositoryInterface::class)->getOrdersInDateRange(
            new DateTimeImmutable('@' . (OrderMonth::FIRST - 86_400)),
            new DateTimeImmutable('@' . (OrderMonth::LAST + 86_400)),
            OrderQueryMode::Raw,
        );

        $this->assertCount(self::generatedCount(OrderSliceTables::ORDERS), $orders);
    }

    #[Test]
    public function every_resolved_sku_is_in_the_pool_except_the_missing_sku_orders(): void
    {
        $this->seedFresh();

        $missingExternalId = self::edgeCase(EdgeCase::MissingSku)->order->externalId;
        $outside = DB::table('shopwired.order_products_resolved')
            ->whereNotIn('sku', FixtureSku::values())
            ->get(['order_external_id', 'sku']);

        $this->assertCount(1, $outside);
        $this->assertSame($missingExternalId, (int) $outside[0]->order_external_id);
        $this->assertSame(EdgeCaseOrders::MISSING_SKU, $outside[0]->sku);
    }

    #[Test]
    public function without_a_profile_flag_no_file_under_examples_is_read(): void
    {
        $path = \base_path(self::PROFILE_PATH);
        $created = ! \file_exists($path);

        try {
            if ($created) {
                @\mkdir(\dirname($path), 0o755, true);
                \file_put_contents($path, 'not json: reading this file would fail the seed');
            }

            $this->seedFresh();

            $this->assertSame(self::generatedCount(OrderSliceTables::ORDERS), DB::table(OrderSliceTables::ORDERS)->count());
            $this->assertEqualsWithDelta(self::generatedTotal(), (float) DB::table(OrderSliceTables::ORDERS)->sum('total'), 0.001);
        } finally {
            if ($created) {
                @\unlink($path);
                @\rmdir(\dirname($path));
                @\rmdir(\dirname($path, 2));
            }
        }
    }

    #[Test]
    public function without_fresh_a_populated_orders_slice_is_refused_and_left_unchanged(): void
    {
        $this->seedFresh();
        $before = $this->sliceCounts();

        $this->artisan('dev:seed-fake')->assertFailed();
        $this->assertSame($before, $this->sliceCounts());

        \config(['local-development.seed' => ['faker_seed' => 252, 'fresh' => false, 'dry_run' => false, 'profile' => null]]);

        try {
            $this->seed(OrderSliceSeeder::class);
            $this->fail('the orders-slice seeder must refuse a populated slice without fresh');
        } catch (RuntimeException $e) {
            $this->assertSame('The orders-slice tables already hold rows; re-run with --fresh to replace them.', $e->getMessage());
        }

        $this->assertSame($before, $this->sliceCounts());
    }

    #[Test]
    public function a_dry_run_writes_no_orders_slice_rows(): void
    {
        DB::statement('TRUNCATE TABLE ' . \implode(', ', OrderSliceTables::INSERT_ORDER));

        $this->artisan('dev:seed-fake', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(\array_fill_keys(OrderSliceTables::INSERT_ORDER, 0), $this->sliceCounts());
    }

    #[Test]
    public function fresh_removes_orders_slice_rows_the_seeder_did_not_write(): void
    {
        $this->insertForeignOrder();
        foreach (OrderSliceTables::INSERT_ORDER as $table) {
            $this->assertSame(1, DB::table($table)->where(...self::externalIdClause($table))->count(), $table);
        }

        $this->seedFresh();

        foreach (OrderSliceTables::INSERT_ORDER as $table) {
            $this->assertSame(0, DB::table($table)->where(...self::externalIdClause($table))->count(), $table);
        }
    }

    private function seedFresh(): void
    {
        $this->artisan('dev:seed-fake', ['--fresh' => true])->assertSuccessful();
    }

    private static function edgeCase(EdgeCase $case): EdgeCaseOrder
    {
        $match = \array_find(EdgeCaseOrders::all(), static fn(EdgeCaseOrder $order): bool => $order->case === $case);
        self::assertNotNull($match);

        return $match;
    }

    private static function generated(): SliceRows
    {
        return new OrderSliceGenerator()->generate(OrderProfile::synthetic(), SeedFakeDataCommand::DEFAULT_FAKER_SEED);
    }

    private static function generatedCount(string $table): int
    {
        return self::generated()->count($table);
    }

    private static function generatedTotal(): float
    {
        return \array_sum(\array_map(static fn(array $order): float => (float) $order['total'], self::generated()->rows(OrderSliceTables::ORDERS)));
    }

    /**
     * @return array{string, int}
     */
    private static function externalIdClause(string $table): array
    {
        return [$table === OrderSliceTables::ORDERS ? 'external_id' : 'order_external_id', self::FOREIGN_EXTERNAL_ID];
    }

    private function insertForeignOrder(): void
    {
        DB::statement('TRUNCATE TABLE ' . \implode(', ', OrderSliceTables::INSERT_ORDER));

        $faker = $this->app->make(Generator::class, ['locale' => 'en_GB']);
        $faker->seed(1);
        $builder = new OrderRowBuilder(new SeedRandom($faker));
        $builder->add(new OrderSpec(
            externalId: self::FOREIGN_EXTERNAL_ID,
            reference: self::FOREIGN_EXTERNAL_ID,
            placedAt: OrderMonth::FIRST,
            paymentMethod: RawPaymentMethod::PayPal,
            status: OrderStatusType::Processing,
            customer: new Customer(1, 'Foreign Row', 'foreign@example.com', '1 Other Road', 'York', 'YO1 7HH'),
            lines: [new LineSpec('FOREIGN-SKU', 10.0, 2.0, 20, skuOverride: 'FOREIGN-OVERRIDE')],
            refunds: [1.0],
        ));
        $rows = $builder->rows();

        foreach ([OrderSliceTables::ORDERS, OrderSliceTables::ORDER_PRODUCTS, OrderSliceTables::ORDER_REFUNDS, OrderSliceTables::ORDER_PRODUCT_EXTRA_DATA] as $table) {
            DB::table($table)->insert($rows->rows($table));
        }

        $order = $rows->rows(OrderSliceTables::ORDERS)[0];
        $owner = ['order_id' => $order['id'], 'order_external_id' => self::FOREIGN_EXTERNAL_ID, 'created_at' => $order['created_at'], 'updated_at' => $order['updated_at']];
        DB::table(OrderSliceTables::ORDER_DISCOUNTS)->insert([...$owner, 'name' => 'Foreign discount', 'value' => 1.0]);
        DB::table(OrderSliceTables::ORDER_ADMIN_COMMENTS)->insert([...$owner, 'content' => 'Foreign comment']);
    }

    /**
     * @return array<string, int>
     */
    private function sliceCounts(): array
    {
        return \array_combine(
            OrderSliceTables::INSERT_ORDER,
            \array_map(static fn(string $table): int => DB::table($table)->count(), OrderSliceTables::INSERT_ORDER),
        );
    }

    private function productsStub(): SliceGeneratorInterface
    {
        $tables = \array_fill_keys(SliceTables::INSERT_ORDER, []);
        $tables[SliceTables::CATEGORIES] = [CategoryRowFactory::new()->raw(['external_id' => self::SALE_CATEGORY_ID])];
        $rows = new SliceRows($tables);

        return new readonly class ($rows) implements SliceGeneratorInterface {
            public function __construct(private SliceRows $rows) {}

            #[Override]
            public function generate(SeedProfile $profile, int $seed, int $saleCategoryId): SliceRows
            {
                return $this->rows;
            }
        };
    }
}
