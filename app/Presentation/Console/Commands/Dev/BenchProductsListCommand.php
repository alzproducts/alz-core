<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands\Dev;

use App\DevTools\Catalog\ProductList\BenchPhpRuntime;
use App\DevTools\Catalog\ProductList\BenchStatistics;
use App\DevTools\Catalog\ProductList\ProductListBenchService;
use App\DevTools\Catalog\ProductList\WriterTimingService;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

/**
 * Local only. Measures the products list p99 under background writes, and times the variation replace and the
 * stock mirror. Every measured process runs with xdebug and pcov off; the command relaunches itself to get there.
 * The writers rewrite seed rows and put them back afterwards; they never call ShopWired or Linnworks.
 */
final class BenchProductsListCommand extends Command
{
    protected $signature = 'dev:bench-products-list
        {--requests=2000 : Measured GET /api/products requests, sent at 2 per second}
        {--warmup=100 : Discarded warm-up requests sent first}
        {--writers-only : Only time the variation replace and the stock mirror (five runs each)}';

    protected $description = 'Benchmark the products list p99 under write load and time its heaviest writers';

    public function handle(BenchPhpRuntime $runtime, WriterTimingService $timings, ProductListBenchService $bench): int
    {
        if ($this->refusedOutsideLocal()) {
            return self::FAILURE;
        }

        try {
            return match (true) {
                $runtime->isInstrumented() => $this->relaunch($runtime),
                $this->option('writers-only') === true => $this->timeWriters($timings),
                default => $this->runLoad($bench),
            };
        } catch (RuntimeException|JsonException $e) { // @ignoreException - delivery boundary: report any bench failure to the operator
            $this->error($e->getMessage());
            $this->line('  Check: local Postgres and Redis are up, no other Octane runs from this checkout, and the seed is loaded.');

            return self::FAILURE;
        }
    }

    private function refusedOutsideLocal(): bool
    {
        $failure = SeedFakeDataCommand::guardFailure();

        if ($failure === null) {
            return false;
        }

        $this->error($failure);
        $this->line('  Check: APP_ENV=local, a loopback DB host and the COR-252 seed (dev:seed-fake).');

        return true;
    }

    /**
     * @throws RuntimeException
     */
    private function relaunch(BenchPhpRuntime $runtime): int
    {
        if ($runtime->wasRelaunched()) {
            $this->error('xdebug or pcov is still active after relaunching with both switched off.');
            $this->line('  Check: php --ini, and whether pcov.enabled is set somewhere that loads after the scan directory.');

            return self::FAILURE;
        }

        $this->info('Relaunching with xdebug and pcov off.');
        $arguments = [$this->getName() ?? 'dev:bench-products-list', '--requests=' . $this->integerOption('requests'), '--warmup=' . $this->integerOption('warmup')];

        if ($this->option('writers-only') === true) {
            $arguments[] = '--writers-only';
        }

        return $runtime->relaunch($arguments, function (string $type, string $output): void {
            $this->output->write($output);
        }) === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @throws RuntimeException
     */
    private function timeWriters(WriterTimingService $timings): int
    {
        $this->info('Timing the daily-sync variation replace and the stock mirror, ' . WriterTimingService::RUNS . ' runs each.');

        $durations = $timings->measure(function (string $writer, int $run, float $milliseconds): void {
            $this->line("  {$writer} run {$run}: {$milliseconds} ms");
        });

        $rows = [];

        foreach ($durations as $writer => $runs) {
            $rows[] = [$writer, \implode(', ', $runs), BenchStatistics::median($runs)];
        }

        $this->table(['Writer', 'Runs (ms)', 'Median (ms)'], $rows);

        return self::SUCCESS;
    }

    /**
     * @throws RuntimeException
     * @throws JsonException
     */
    private function runLoad(ProductListBenchService $bench): int
    {
        if ($this->integerOption('requests') < 1) {
            $this->error('--requests must be a positive whole number.');

            return self::FAILURE;
        }

        $summary = $bench->run($this->integerOption('warmup'), $this->integerOption('requests'), function (string $message): void {
            $this->info($message);
        });

        $this->table(['Metric', 'Value'], \array_map(
            static fn(string $key, mixed $value): array => [$key, \json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)],
            \array_keys($summary),
            $summary,
        ));

        return self::SUCCESS;
    }

    private function integerOption(string $name): int
    {
        $value = $this->option($name);

        return \is_string($value) && \ctype_digit($value) ? (int) $value : 0;
    }
}
