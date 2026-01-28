<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Presentation\Jobs\Shopwired\SyncShopwiredOrdersRangeJob;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Backfill historical orders from ShopWired API.
 *
 * Dispatches multiple monthly sync jobs to import historical order data.
 * Jobs are queued and processed by Horizon workers, with rate limiting
 * handled automatically by the transport layer.
 *
 * Examples:
 *   php artisan shopwired:backfill-orders --months=12
 *   php artisan shopwired:backfill-orders --months=10 --offset=2
 *   php artisan shopwired:backfill-orders --months=6 --dry-run
 */
final class BackfillShopwiredOrdersCommand extends Command
{
    protected $signature = 'shopwired:backfill-orders
                            {--months=12 : Number of months to sync}
                            {--offset=0 : Month offset (0 = include current partial month up to now, 1 = start from last complete month)}
                            {--dry-run : Show what would be dispatched without dispatching}';

    protected $description = 'Backfill historical orders from ShopWired API';

    public function handle(): int
    {
        $months = (int) $this->option('months');
        $offset = (int) $this->option('offset');
        $dryRun = $this->option('dry-run');

        if ($months < 1) {
            $this->error('--months must be at least 1');

            return self::FAILURE;
        }

        if ($offset < 0) {
            $this->error('--offset cannot be negative');

            return self::FAILURE;
        }

        // Use startOfMonth() to ensure gap-free calendar month windows.
        // Direct month arithmetic (e.g., "Mar 31 - 1 month = Feb 28") creates gaps
        // because it doesn't maintain end-of-month semantics.
        $now = CarbonImmutable::now();

        $this->info($dryRun ? 'DRY RUN - Would dispatch:' : "Dispatching {$months} monthly sync jobs...");
        $this->newLine();

        $this->table(
            ['#', 'From', 'To', 'Period'],
            $this->buildJobTable($now, $months, $offset),
        );

        if ($dryRun) {
            $this->newLine();
            $this->warn('No jobs dispatched (dry run). Remove --dry-run to execute.');

            return self::SUCCESS;
        }

        $this->newLine();

        for ($i = 0; $i < $months; $i++) {
            $monthsAgo = $offset + $i;
            $from = $now->startOfMonth()->subMonths($monthsAgo);

            // First job with offset=0: sync up to NOW (partial current month)
            // All other jobs: sync complete calendar months
            $to = ($i === 0 && $offset === 0)
                ? $now
                : $now->startOfMonth()->subMonths($monthsAgo - 1);

            SyncShopwiredOrdersRangeJob::dispatch($from->toDateTimeImmutable(), $to->toDateTimeImmutable());
        }

        $this->info("✓ {$months} jobs dispatched to queue.");
        $this->line('  Monitor progress: php artisan horizon');

        return self::SUCCESS;
    }

    /**
     * Build table data showing what will be dispatched.
     *
     * @return list<array{int, string, string, string}>
     */
    private function buildJobTable(CarbonImmutable $now, int $months, int $offset): array
    {
        $rows = [];

        for ($i = 0; $i < $months; $i++) {
            $monthsAgo = $offset + $i;
            $from = $now->startOfMonth()->subMonths($monthsAgo);

            // First job with offset=0: sync up to NOW (partial current month)
            // All other jobs: sync complete calendar months
            $to = ($i === 0 && $offset === 0)
                ? $now
                : $now->startOfMonth()->subMonths($monthsAgo - 1);

            // Period label: show "Current" for partial month, otherwise month name
            $period = ($i === 0 && $offset === 0)
                ? $from->format('M Y') . ' (partial)'
                : $from->format('M Y');

            $rows[] = [
                $i + 1,
                $from->format('Y-m-d H:i'),
                $to->format('Y-m-d H:i'),
                $period,
            ];
        }

        return $rows;
    }
}
