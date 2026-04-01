<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\Shopwired\UseCases\BackfillShopwiredOrdersUseCase;
use Carbon\CarbonImmutable;
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
                            {--offset=0 : Month offset (0 = include current partial month up to now, 1 = start from last complete month)}
                            {--dry-run : Show what would be dispatched without dispatching}';

    protected $description = 'Backfill historical orders from ShopWired API';

    public function handle(BackfillShopwiredOrdersUseCase $dispatchUseCase): int
    {
        $months = (int) $this->option('months');
        $offset = (int) $this->option('offset');

        $validationError = $this->validateOptions($months, $offset);
        if ($validationError !== null) {
            $this->error($validationError);
            return self::FAILURE;
        }

        $now = CarbonImmutable::now();
        $dryRun = $this->option('dry-run');
        $this->info($dryRun ? 'DRY RUN - Would dispatch:' : "Dispatching {$months} monthly sync jobs...");
        $this->newLine();
        $this->table(['#', 'From', 'To', 'Period'], $this->buildJobTable($now, $months, $offset));

        return $dryRun ? $this->displayDryRunNotice() : $this->dispatchJobs($dispatchUseCase, $now, $months, $offset);
    }

    private function validateOptions(int $months, int $offset): ?string
    {
        if ($months < 1) {
            return '--months must be at least 1';
        }

        return $offset < 0 ? '--offset cannot be negative' : null;
    }

    private function displayDryRunNotice(): int
    {
        $this->newLine();
        $this->warn('No jobs dispatched (dry run). Remove --dry-run to execute.');

        return self::SUCCESS;
    }

    private function dispatchJobs(BackfillShopwiredOrdersUseCase $dispatchUseCase, CarbonImmutable $now, int $months, int $offset): int
    {
        $this->newLine();
        $ranges = $this->buildRanges($now, $months, $offset);
        $dispatched = $dispatchUseCase->execute($ranges);
        $this->info("✓ {$dispatched} jobs dispatched to queue.");
        $this->line('  Monitor progress: php artisan horizon');

        return self::SUCCESS;
    }

    /**
     * @return list<array{from: DateTimeImmutable, to: DateTimeImmutable}>
     */
    private function buildRanges(CarbonImmutable $now, int $months, int $offset): array
    {
        $ranges = [];

        for ($i = 0; $i < $months; $i++) {
            $ranges[] = $this->buildSingleRange($now, $offset + $i, $i === 0 && $offset === 0);
        }

        return $ranges;
    }

    /**
     * @return array{from: DateTimeImmutable, to: DateTimeImmutable}
     */
    private function buildSingleRange(CarbonImmutable $now, int $monthsAgo, bool $isPartialMonth): array
    {
        $from = $now->startOfMonth()->subMonths($monthsAgo);
        $to = $isPartialMonth ? $now : $now->startOfMonth()->subMonths($monthsAgo - 1);

        return ['from' => $from->toDateTimeImmutable(), 'to' => $to->toDateTimeImmutable()];
    }

    /**
     * @return list<array{int, string, string, string}>
     */
    private function buildJobTable(CarbonImmutable $now, int $months, int $offset): array
    {
        $rows = [];

        foreach ($this->buildRanges($now, $months, $offset) as $i => $range) {
            $rows[] = $this->buildTableRow($range, $i);
        }

        return $rows;
    }

    /**
     * @param array{from: DateTimeImmutable, to: DateTimeImmutable} $range
     * @return array{int, string, string, string}
     */
    private function buildTableRow(array $range, int $index): array
    {
        $from = CarbonImmutable::instance($range['from']);
        $to = CarbonImmutable::instance($range['to']);

        $period = ($index === 0 && (int) $this->option('offset') === 0)
            ? $from->format('M Y') . ' (partial)'
            : $from->format('M Y');

        return [$index + 1, $from->format('Y-m-d H:i'), $to->format('Y-m-d H:i'), $period];
    }
}
