<?php

declare(strict_types=1);

namespace Tests\Integration\Presentation\Console\Commands;

use App\Presentation\Console\Commands\Dev\SeedFakeDataCommand;
use Database\Factories\ProductSlice\CategoryRowFactory;
use Database\Factories\ProductSlice\CustomFieldDefinitionRowFactory;
use Database\Factories\ProductSlice\ProductRowFactory;
use Database\Seeders\ProductSlice\Contracts\SliceGeneratorInterface;
use Database\Seeders\ProductSlice\SeedProfile;
use Database\Seeders\ProductSlice\SliceRows;
use Database\Seeders\ProductSlice\SliceTables;
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
 * `dev:seed-fake` against the local database with a stubbed small generator, inside a rolled-back transaction
 * (AC2, AC6). Serial and outside the default suite: `--fresh` TRUNCATEs the shared slice tables.
 */
#[CoversClass(SeedFakeDataCommand::class)]
#[Group('seed-command')]
final class SeedFakeDataCommandTest extends TestCase
{
    private const int SALE_CATEGORY_ID = 2_000_000_001;

    private const int DEFINITION_EXTERNAL_ID = 2_030_999_999;

    private const string DEFINITION_ID = '6f1c1c52-6a4f-4a8e-9b1e-252000000001';

    /**
     * Custom-field definitions are upserted, never truncated, so their settings rows must survive `--fresh`.
     *
     * @var list<string>
     */
    private const array UNTOUCHED_TABLES = [
        'auth.users',
        'catalog.custom_field_general_settings',
        'catalog.custom_field_product_settings',
        'shopwired.brands',
    ];

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
        $this->insertDefinitionFixture();
    }

    #[Override]
    protected function tearDown(): void
    {
        DB::rollBack();

        parent::tearDown();
    }

    #[Test]
    public function fresh_seeds_the_stub_slice_without_dispatching_jobs_or_calling_out(): void
    {
        $this->useGenerator($this->stubGenerator());

        $this->artisan('dev:seed-fake', ['--fresh' => true])->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame(1, $this->rowCount(SliceTables::CATEGORIES));
        $this->assertSame(1, $this->rowCount(SliceTables::PRODUCTS));
        $this->assertSame(0, $this->rowCount(SliceTables::PRODUCT_VARIATIONS));
        $this->assertSame(0, $this->rowCount(SliceTables::STOCK_ITEMS));
        $this->assertSame(0, $this->rowCount(SliceTables::STOCK_ITEM_EXTENDED_PROPERTIES));
    }

    #[Test]
    public function fresh_keeps_non_slice_rows_and_custom_field_definition_uuids(): void
    {
        $this->useGenerator($this->stubGenerator());
        $before = $this->untouchedCounts();
        $definitionIds = $this->definitionIds();

        $this->artisan('dev:seed-fake', ['--fresh' => true])->assertSuccessful();

        $this->assertSame($before, $this->untouchedCounts());
        $this->assertSame($definitionIds, $this->definitionIds());
        $this->assertSame(
            self::DEFINITION_ID,
            DB::table(SliceTables::CUSTOM_FIELD_DEFINITIONS)->where('external_id', self::DEFINITION_EXTERNAL_ID)->value('id'),
        );
        $this->assertSame(
            'Reseeded label',
            DB::table(SliceTables::CUSTOM_FIELD_DEFINITIONS)->where('external_id', self::DEFINITION_EXTERNAL_ID)->value('label'),
        );
    }

    #[Test]
    public function without_fresh_it_refuses_when_slice_tables_hold_rows_and_changes_nothing(): void
    {
        $this->useGenerator($this->stubGenerator());
        $this->artisan('dev:seed-fake', ['--fresh' => true])->assertSuccessful();
        $before = $this->sliceCounts();

        $this->artisan('dev:seed-fake')
            ->expectsOutput('Seeding failed: The slice tables already hold rows; re-run with --fresh to replace them.')
            ->assertFailed();

        $this->assertSame($before, $this->sliceCounts());
    }

    #[Test]
    public function a_seeder_exception_exits_with_failure_and_an_error_line(): void
    {
        $this->useGenerator(new class implements SliceGeneratorInterface {
            #[Override]
            public function generate(SeedProfile $profile, int $seed, int $saleCategoryId): SliceRows
            {
                throw new RuntimeException('generator exploded');
            }
        });

        $this->artisan('dev:seed-fake', ['--fresh' => true])
            ->expectsOutput('Seeding failed: generator exploded')
            ->assertFailed();
    }

    private function useGenerator(SliceGeneratorInterface $generator): void
    {
        $this->app->instance(SliceGeneratorInterface::class, $generator);
    }

    private function stubGenerator(): SliceGeneratorInterface
    {
        $tables = \array_fill_keys(SliceTables::INSERT_ORDER, []);
        $tables[SliceTables::CUSTOM_FIELD_DEFINITIONS] = [
            CustomFieldDefinitionRowFactory::new()->raw([
                'external_id' => self::DEFINITION_EXTERNAL_ID,
                'name' => 'seed_command_fixture',
                'label' => 'Reseeded label',
            ]),
        ];
        $tables[SliceTables::CATEGORIES] = [CategoryRowFactory::new()->raw(['external_id' => self::SALE_CATEGORY_ID])];
        $tables[SliceTables::PRODUCTS] = [ProductRowFactory::new()->raw(['external_id' => 2_010_000_000, 'sku' => 'SEED-CMD-1'])];

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

    private function insertDefinitionFixture(): void
    {
        DB::table(SliceTables::CUSTOM_FIELD_DEFINITIONS)->insert([
            'id' => self::DEFINITION_ID,
            'external_id' => self::DEFINITION_EXTERNAL_ID,
            'name' => 'seed_command_fixture',
            'type' => 'text',
            'label' => 'Original label',
            'item_type' => 'product',
            'sort_order' => 0,
            'created_at' => '2026-01-01 00:00:00+00',
            'updated_at' => '2026-01-01 00:00:00+00',
        ]);
        DB::table('catalog.custom_field_general_settings')->insert([
            'custom_field_definition_id' => self::DEFINITION_ID,
            'admin_only' => false,
        ]);
        DB::table('catalog.custom_field_product_settings')->insert([
            'custom_field_definition_id' => self::DEFINITION_ID,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function untouchedCounts(): array
    {
        return \array_combine(self::UNTOUCHED_TABLES, \array_map($this->rowCount(...), self::UNTOUCHED_TABLES));
    }

    /**
     * @return array<string, int>
     */
    private function sliceCounts(): array
    {
        return \array_combine(SliceTables::INSERT_ORDER, \array_map($this->rowCount(...), SliceTables::INSERT_ORDER));
    }

    /**
     * @return array<int|string, mixed>
     */
    private function definitionIds(): array
    {
        return DB::table(SliceTables::CUSTOM_FIELD_DEFINITIONS)->orderBy('external_id')->pluck('id', 'external_id')->all();
    }

    private function rowCount(string $table): int
    {
        return DB::table($table)->count();
    }
}
