<?php

declare(strict_types=1);

namespace Database\Seeders\ProductSlice;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Catalog\Models\CostPriceChangeLogModel;
use App\Infrastructure\Catalog\Product\Models\ProductExtraDataModel;
use App\Infrastructure\Catalog\Product\Models\ProductModel;
use App\Infrastructure\Catalog\Product\Models\ProductVariationModel;
use App\Infrastructure\Database\DatabaseGateway;
use App\Infrastructure\Linnworks\Models\StockItemExtendedPropertyModel;
use App\Infrastructure\Linnworks\Models\StockItemModel;
use App\Infrastructure\Linnworks\Models\StockItemSupplierModel;
use App\Infrastructure\Linnworks\Models\SupplierModel;
use App\Infrastructure\Operations\Models\PricePeriodModel;
use App\Infrastructure\Persistence\EloquentGateway;
use App\Infrastructure\Shopwired\Models\CategoryModel;
use App\Infrastructure\Shopwired\Models\CustomFieldDefinitionModel;
use App\Infrastructure\Shopwired\Models\FilterGroupDefinitionModel;
use App\Presentation\Console\Commands\Dev\SeedFakeDataCommand;
use Database\Seeders\ProductSlice\Contracts\SliceGeneratorInterface;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use JsonException;
use RuntimeException;

/**
 * Writes the generated slice. Configured through `local-development.seed`, which only `dev:seed-fake` sets;
 * a direct `db:seed` therefore never truncates.
 */
final class ProductSliceSeeder extends Seeder
{
    private const int CHUNK_SIZE = 500;

    /**
     * Tables written through their Eloquent write model; the popularity snapshot tables have none.
     *
     * @var array<string, class-string<Model>>
     */
    private const array WRITE_MODELS = [
        SliceTables::CATEGORIES => CategoryModel::class,
        SliceTables::FILTER_GROUPS => FilterGroupDefinitionModel::class,
        SliceTables::PRODUCTS => ProductModel::class,
        SliceTables::PRODUCT_VARIATIONS => ProductVariationModel::class,
        SliceTables::STOCK_ITEMS => StockItemModel::class,
        SliceTables::STOCK_ITEM_EXTENDED_PROPERTIES => StockItemExtendedPropertyModel::class,
        SliceTables::SUPPLIERS => SupplierModel::class,
        SliceTables::STOCK_ITEM_SUPPLIERS => StockItemSupplierModel::class,
        SliceTables::PRODUCT_EXTRA_DATA => ProductExtraDataModel::class,
        SliceTables::PRICE_PERIODS => PricePeriodModel::class,
        SliceTables::COST_PRICE_CHANGES => CostPriceChangeLogModel::class,
    ];

    public function __construct(
        private readonly DatabaseGateway $database,
        private readonly EloquentGateway $eloquent,
    ) {}

    /**
     * @throws BindingResolutionException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws JsonException
     * @throws RuntimeException When the guard refuses or the slice tables already hold rows without `fresh`
     */
    public function run(): void
    {
        $failure = SeedFakeDataCommand::guardFailure();

        if ($failure !== null) {
            throw new RuntimeException($failure);
        }

        $settings = \config()->array('local-development.seed');
        $fresh = ($settings['fresh'] ?? false) === true;

        $rows = $this->generator()->generate(
            SeedProfile::fromFile(),
            \is_int($settings['faker_seed'] ?? null) ? $settings['faker_seed'] : SeedFakeDataCommand::DEFAULT_FAKER_SEED,
            \config()->integer('shopwired.sale_category_id'),
        );

        if (($settings['dry_run'] ?? false) === true) {
            $this->printCounts($rows, 'Dry run — rows that would be seeded');

            return;
        }

        if (! $fresh && $this->sliceHasRows()) {
            throw new RuntimeException('The slice tables already hold rows; re-run with --fresh to replace them.');
        }

        $this->database->transact(function () use ($rows, $fresh): void {
            if ($fresh) {
                $this->truncateSlice();
            }

            $this->write($rows);
        });

        $this->printCounts($rows, 'Seeded rows');
    }

    /**
     * @throws BindingResolutionException
     */
    private function generator(): SliceGeneratorInterface
    {
        if ($this->container->bound(SliceGeneratorInterface::class)) {
            /** @var SliceGeneratorInterface */
            return $this->container->make(SliceGeneratorInterface::class);
        }

        return new ProfileSliceGenerator();
    }

    private function sliceHasRows(): bool
    {
        return \array_any(
            SliceTables::INSERT_ORDER,
            fn(string $table): bool => $this->database->connection()->table($table)->exists(),
        );
    }

    /**
     * Plain TRUNCATE without CASCADE, never Laravel's `truncate()`: custom-field definitions stay, and their
     * cascading settings tables with them.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function truncateSlice(): void
    {
        $tables = \array_values(\array_diff(SliceTables::INSERT_ORDER, [SliceTables::CUSTOM_FIELD_DEFINITIONS]));

        $this->database->runSql('TRUNCATE TABLE ' . \implode(', ', $tables));
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function write(SliceRows $rows): void
    {
        foreach (\array_chunk($rows->rows(SliceTables::CUSTOM_FIELD_DEFINITIONS), self::CHUNK_SIZE) as $chunk) {
            $this->eloquent->upsertMany(CustomFieldDefinitionModel::class, $chunk, ['external_id']);
        }

        foreach (SliceTables::INSERT_ORDER as $table) {
            if ($table === SliceTables::CUSTOM_FIELD_DEFINITIONS) {
                continue;
            }

            foreach (\array_chunk($rows->rows($table), self::CHUNK_SIZE) as $chunk) {
                $this->insertChunk($table, $chunk);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $chunk
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function insertChunk(string $table, array $chunk): void
    {
        $model = self::WRITE_MODELS[$table] ?? null;

        if ($model !== null) {
            $this->eloquent->insertMany($model, $chunk);

            return;
        }

        $this->database->connection()->table($table)->insert($chunk);
    }

    private function printCounts(SliceRows $rows, string $heading): void
    {
        $this->command->info($heading);
        $this->command->table(
            ['Table', 'Rows'],
            \array_map(static fn(string $table): array => [$table, $rows->count($table)], SliceTables::INSERT_ORDER),
        );
    }
}
