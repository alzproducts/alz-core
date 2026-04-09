<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands\Dev;

use App\Application\Linnworks\Enums\OrderSyncTier;
use App\Infrastructure\Jobs\Linnworks\SyncFastPurchaseOrdersJob;
use App\Infrastructure\Jobs\Linnworks\SyncLinnworksOrdersJob;
use App\Infrastructure\Jobs\Linnworks\SyncLinnworksStockItemsJob;
use App\Infrastructure\Jobs\Linnworks\SyncLinnworksSuppliersJob;
use App\Infrastructure\Jobs\ReviewsIo\SyncProductRatingsJob;
use App\Infrastructure\Jobs\Shopwired\SyncShopwiredBrandsJob;
use App\Infrastructure\Jobs\Shopwired\SyncShopwiredCategoriesJob;
use App\Infrastructure\Jobs\Shopwired\SyncShopwiredCustomersJob;
use App\Infrastructure\Jobs\Shopwired\SyncShopwiredCustomFieldsJob;
use App\Infrastructure\Jobs\Shopwired\SyncShopwiredFilterGroupsJob;
use App\Infrastructure\Jobs\Shopwired\SyncShopwiredOrdersJob;
use App\Infrastructure\Jobs\Shopwired\SyncShopwiredProductsJob;
use Illuminate\Console\Command;

/**
 * Dispatch all sync jobs to populate a fresh local database.
 *
 * Local environment only. Safe to re-run — jobs are idempotent.
 *
 * Note: Many sync jobs implement ShouldBeUnique, which acquires a Redis lock before
 * queuing. If jobs appear to dispatch successfully but never appear in the queue,
 * check for stranded unique locks: `redis-cli KEYS "*laravel_unique_job*"` and
 * delete any with no TTL (`TTL -2`).
 */
final class SeedLocalDatabaseCommand extends Command
{
    protected $signature = 'dev:seed-sync
        {--incl-pii : Also dispatch PII-containing sync jobs (customers, orders)}
        {--pii-only : Only dispatch PII jobs, skip core}
        {--dry-run : Show what would be dispatched without dispatching}';

    protected $description = 'Dispatch all sync jobs to seed a fresh local database';

    /**
     * @var list<array{class-string, string}>
     */
    private const array CORE_JOBS = [
        [SyncShopwiredBrandsJob::class, 'Brands (~30)'],
        [SyncShopwiredCategoriesJob::class, 'Categories (~50)'],
        [SyncShopwiredCustomFieldsJob::class, 'Custom fields (~100-150)'],
        [SyncShopwiredFilterGroupsJob::class, 'Filter groups (~10-20)'],
        [SyncLinnworksSuppliersJob::class, 'Suppliers'],
        [SyncShopwiredProductsJob::class, 'Products (~1,500)'],
        [SyncLinnworksStockItemsJob::class, 'Stock items (~10k)'],
        [SyncProductRatingsJob::class, 'Product ratings'],
        [SyncFastPurchaseOrdersJob::class, 'Purchase orders (open/pending/recent)'],
    ];

    public function handle(): int
    {
        if (! \app()->environment('local')) {
            $this->error('This command can only run in the local environment.');

            return self::FAILURE;
        }

        if ($this->option('incl-pii') && $this->option('pii-only')) {
            $this->error('--incl-pii and --pii-only are mutually exclusive.');

            return self::FAILURE;
        }

        $dryRun = $this->option('dry-run');

        $this->runMigrations($dryRun);

        return $this->seedDatabase($dryRun, $this->option('incl-pii'), $this->option('pii-only'));
    }

    private function runMigrations(bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        $this->comment(' Running migrations...');
        $this->call('migrate');
        $this->newLine();
    }

    private function seedDatabase(bool $dryRun, bool $inclPii, bool $piiOnly): int
    {
        $this->info('Seed Local Database' . ($dryRun ? ' (dry run)' : '') . ' — Dispatching sync jobs to queue');
        $this->newLine();

        $dispatched = 0;

        if (! $piiOnly) {
            $dispatched += $this->dispatchCoreJobs($dryRun);
        }

        if ($inclPii || $piiOnly) {
            $dispatched += $this->dispatchPiiJobs($dryRun);
        }

        $this->printSummary($dispatched, $dryRun);

        return self::SUCCESS;
    }

    private function dispatchCoreJobs(bool $dryRun): int
    {
        $this->comment(' Core reference data:');

        foreach (self::CORE_JOBS as [$jobClass, $label]) {
            if ($dryRun) {
                $this->line("   [dry-run] {$label}");
            } else {
                $jobClass::dispatch();
                $this->line("   <info>✓</info> {$label} dispatched");
            }
        }

        return \count(self::CORE_JOBS);
    }

    private function dispatchPiiJobs(bool $dryRun): int
    {
        $this->comment(' PII sync jobs (customers & orders):');

        $piiJobs = [
            ['label' => 'Customers (all trade, 10 pages non-trade)', 'job' => new SyncShopwiredCustomersJob(null, 10)],
            ['label' => 'ShopWired orders (quick, ~500 orders)', 'job' => new SyncShopwiredOrdersJob(5)],
            ['label' => 'Linnworks orders (monthly, ~28 days)', 'job' => new SyncLinnworksOrdersJob(OrderSyncTier::Monthly)],
        ];

        foreach ($piiJobs as $entry) {
            if ($dryRun) {
                $this->line("   [dry-run] {$entry['label']}");
            } else {
                \dispatch($entry['job']);
                $this->line("   <info>✓</info> {$entry['label']} dispatched");
            }
        }

        return \count($piiJobs);
    }

    private function printSummary(int $dispatched, bool $dryRun): void
    {
        $this->newLine();
        $verb = $dryRun ? 'would be dispatched' : 'dispatched';
        $this->info("{$dispatched} job(s) {$verb}.");

        if (! $dryRun) {
            $this->line('  Ensure your queue worker is running: <comment>php artisan horizon</comment>');
        }
    }
}
