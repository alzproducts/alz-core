<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands\Dev;

use Illuminate\Console\Command;
use Throwable;

/**
 * Seed the local database with a synthetic, production-shaped products slice.
 *
 * Local and testing environments against a loopback database only. No sync job is dispatched
 * and no external API is called.
 */
final class SeedFakeDataCommand extends Command
{
    public const int DEFAULT_FAKER_SEED = 252;

    /**
     * intentional: repeats the seeder's singleton slot bounds — app/ must not import Database\Seeders.
     */
    private const int SALE_CATEGORY_SLOT_MIN = 2_000_000_000;

    private const int SALE_CATEGORY_SLOT_MAX = 2_000_000_099;

    private const array LOOPBACK_HOSTS = ['127.0.0.1', 'localhost', '::1'];

    private const string SEEDER = 'Database\\Seeders\\ProductSlice\\ProductSliceSeeder';

    protected $signature = 'dev:seed-fake
        {--fresh : Truncate the slice tables before seeding}
        {--dry-run : Generate the slice and print per-table counts without writing}
        {--seed= : Faker seed (defaults to SEED_FAKER_SEED, then a fixed seed)}';

    protected $description = 'Seed a synthetic production-shaped products slice into the local database';

    /**
     * The reason seeding is refused here, or null when it may run.
     */
    public static function guardFailure(): ?string
    {
        if (! \app()->environment('local', 'testing')) {
            return 'Refusing to seed: the environment is not local or testing.';
        }

        $connection = \config()->string('database.default');

        if (! \in_array(\config()->get("database.connections.{$connection}.host"), self::LOOPBACK_HOSTS, true)) {
            return 'Refusing to seed: the default database connection host is not a loopback address.';
        }

        $saleCategoryId = \config()->get('shopwired.sale_category_id');

        if (! \is_int($saleCategoryId) || $saleCategoryId < self::SALE_CATEGORY_SLOT_MIN || $saleCategoryId > self::SALE_CATEGORY_SLOT_MAX) {
            return 'Refusing to seed: the sale category ID is outside the seed\'s reserved slots 2000000000-2000000099.';
        }

        return null;
    }

    public function handle(): int
    {
        $seed = $this->preflightSeed();

        if ($seed === null) {
            return self::FAILURE;
        }

        $dryRun = $this->option('dry-run');

        if (! $dryRun && $this->call('migrate') !== self::SUCCESS) {
            $this->error('Migrations failed; nothing was seeded.');

            return self::FAILURE;
        }

        \config(['local-development.seed' => ['faker_seed' => $seed, 'fresh' => $this->option('fresh'), 'dry_run' => $dryRun]]);

        return $this->runSeeder();
    }

    /**
     * The faker seed to use, or null (with the reason printed) when seeding must not start.
     */
    private function preflightSeed(): ?int
    {
        $failure = self::guardFailure();

        if ($failure !== null) {
            $this->error($failure);
            $this->line('  Check: APP_ENV, DB_HOST and SHOPWIRED_SALE_CATEGORY_ID (see .env.example)');

            return null;
        }

        $seed = $this->resolveSeed();

        if ($seed === null) {
            $this->error('The faker seed must be a non-negative integer.');
            $this->line('  Check: --seed and SEED_FAKER_SEED');
        }

        return $seed;
    }

    private function runSeeder(): int
    {
        try {
            $exitCode = $this->call('db:seed', ['--class' => self::SEEDER, '--force' => true]);
        } catch (Throwable $e) { // @ignoreException - delivery boundary: report any seeder failure to the operator
            $this->error('Seeding failed: ' . $e->getMessage());
            $this->line('  Check: re-run with --fresh to replace an existing slice');

            return self::FAILURE;
        }

        if ($exitCode !== self::SUCCESS) {
            $this->error('Seeding failed.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function resolveSeed(): ?int
    {
        $option = $this->option('seed');
        $configured = \config()->get('local-development.seed.faker_seed');

        $raw = match (true) {
            \is_string($option) && $option !== '' => $option,
            \is_int($configured) => (string) $configured,
            \is_string($configured) && $configured !== '' => $configured,
            default => (string) self::DEFAULT_FAKER_SEED,
        };

        return \ctype_digit($raw) ? (int) $raw : null;
    }
}
