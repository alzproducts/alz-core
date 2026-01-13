<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Presentation\Jobs\SyncShopwiredOrdersJob;
use DateMalformedStringException;
use DateTimeImmutable;
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
                            {--offset=0 : Start from X months ago (skip recent months)}
                            {--dry-run : Show what would be dispatched without dispatching}';

    protected $description = 'Backfill historical orders from ShopWired API';

    /**
     * @throws DateMalformedStringException Never in practice (hardcoded valid strings)
     */
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

        $now = new DateTimeImmutable('now');

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
            $to = $now->modify("-{$monthsAgo} months");
            $from = $to->modify('-1 month');

            SyncShopwiredOrdersJob::dispatch($from, $to);
        }

        $this->info("✓ {$months} jobs dispatched to queue.");
        $this->line('  Monitor progress: php artisan horizon');

        return self::SUCCESS;
    }

    /**
     * Build table data showing what will be dispatched.
     *
     * @return list<array{int, string, string, string}>
     *
     * @throws DateMalformedStringException Never in practice (hardcoded valid strings)
     */
    private function buildJobTable(DateTimeImmutable $now, int $months, int $offset): array
    {
        $rows = [];

        for ($i = 0; $i < $months; $i++) {
            $monthsAgo = $offset + $i;
            $to = $now->modify("-{$monthsAgo} months");
            $from = $to->modify('-1 month');

            $rows[] = [
                $i + 1,
                $from->format('Y-m-d H:i'),
                $to->format('Y-m-d H:i'),
                $from->format('M Y'),
            ];
        }

        return $rows;
    }
}
