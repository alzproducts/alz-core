<?php

declare(strict_types=1);

namespace Database\Seeders\OrderSlice;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Database\DatabaseGateway;
use App\Infrastructure\Persistence\EloquentGateway;
use App\Infrastructure\Shopwired\Models\OrderModel;
use App\Infrastructure\Shopwired\Models\OrderProductExtraDataModel;
use App\Infrastructure\Shopwired\Models\OrderProductModel;
use App\Infrastructure\Shopwired\Models\OrderRefundModel;
use App\Presentation\Console\Commands\Dev\SeedFakeDataCommand;
use Database\Seeders\ProductSlice\SliceRows;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use JsonException;
use LogicException;
use RuntimeException;

/**
 * Writes the orders slice under the products slice's rules: same guard, `fresh`, `dry_run` and refuse-when-populated
 * (COR-299/D5). A profile is read only from the `profile` path `dev:seed-fake --profile` sets (COR-299/D12).
 */
final class OrderSliceSeeder extends Seeder
{
    private const int CHUNK_SIZE = 500;

    /** @var array<string, class-string<Model>> */
    private const array WRITE_MODELS = [
        OrderSliceTables::ORDERS => OrderModel::class,
        OrderSliceTables::ORDER_PRODUCTS => OrderProductModel::class,
        OrderSliceTables::ORDER_REFUNDS => OrderRefundModel::class,
        OrderSliceTables::ORDER_PRODUCT_EXTRA_DATA => OrderProductExtraDataModel::class,
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
     * @throws RuntimeException When the guard refuses or the orders-slice tables already hold rows without `fresh`
     */
    public function run(): void
    {
        $failure = SeedFakeDataCommand::guardFailure();

        if ($failure !== null) {
            throw new RuntimeException($failure);
        }

        $settings = \config()->array('local-development.seed');
        $fresh = ($settings['fresh'] ?? false) === true;
        $rows = self::generate($settings);

        if (($settings['dry_run'] ?? false) === true) {
            $this->printCounts($rows, 'Dry run — orders-slice rows that would be seeded');

            return;
        }

        if (! $fresh && $this->sliceHasRows()) {
            throw new RuntimeException('The orders-slice tables already hold rows; re-run with --fresh to replace them.');
        }

        $this->write($rows, $fresh);
        $this->printCounts($rows, 'Seeded orders-slice rows');
    }

    /**
     * @param array<mixed> $settings
     *
     * @throws BindingResolutionException
     * @throws JsonException
     */
    private static function generate(array $settings): SliceRows
    {
        $profilePath = $settings['profile'] ?? null;

        return new OrderSliceGenerator()->generate(
            \is_string($profilePath) && $profilePath !== '' ? OrderProfile::fromFile($profilePath) : OrderProfile::synthetic(),
            \is_int($settings['faker_seed'] ?? null) ? $settings['faker_seed'] : SeedFakeDataCommand::DEFAULT_FAKER_SEED,
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function write(SliceRows $rows, bool $fresh): void
    {
        $this->database->transact(function () use ($rows, $fresh): void {
            if ($fresh) {
                $this->database->runSql('TRUNCATE TABLE ' . \implode(', ', OrderSliceTables::INSERT_ORDER));
            }

            foreach (self::WRITE_MODELS as $table => $model) {
                foreach (\array_chunk($rows->rows($table), self::CHUNK_SIZE) as $chunk) {
                    $this->eloquent->insertMany($model, $chunk);
                }
            }
        });
    }

    private function sliceHasRows(): bool
    {
        return \array_any(
            OrderSliceTables::INSERT_ORDER,
            fn(string $table): bool => $this->database->connection()->table($table)->exists(),
        );
    }

    private function printCounts(SliceRows $rows, string $heading): void
    {
        $command = $this->command ?? throw new LogicException('OrderSliceSeeder has no console command; run it through db:seed or Seeder::call().');

        $command->info($heading);
        $command->table(
            ['Table', 'Rows'],
            \array_map(static fn(string $table): array => [$table, $rows->count($table)], OrderSliceTables::INSERT_ORDER),
        );
    }
}
