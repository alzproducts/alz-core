<?php

declare(strict_types=1);

namespace App\DevTools\Catalog\ProductList;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use RuntimeException;

/**
 * The p99 load run: background writers, then local Octane, then the paced request mix, with the server-side
 * durations read from octane.log over the measured requests only.
 */
final readonly class ProductListBenchService
{
    private const string MEASURED_PATH = '/api/products';

    private const int LOG_FLUSH_SECONDS = 3;

    public function __construct(
        private OctaneBenchServer $server,
        private BenchRequestLoad $load,
        private BenchWriteLoad $writes,
        private BenchWriterPool $pool,
        private BenchPhpRuntime $runtime,
        private Filesystem $files,
    ) {}

    /**
     * @param Closure(string): void $report
     *
     * @return array<string, mixed> The run's summary, also written to tmp/cor-258/bench/
     *
     * @throws RuntimeException
     * @throws JsonException
     */
    public function run(int $warmup, int $requests, Closure $report): array
    {
        $notes = $this->writes->captureBaseline();
        $runSeconds = ($warmup + $requests) / BenchRequestLoad::REQUESTS_PER_SECOND;
        $writers = BenchWriteLoad::writers(($warmup / BenchRequestLoad::REQUESTS_PER_SECOND) + 60.0);

        // intentional: fork before Octane starts, so no child holds the server process handle and kills it on exit.
        $this->pool->start($writers, $this->writes, $runSeconds + 600.0);

        try {
            $window = $this->sendLoad($warmup, $requests, $report);
        } finally {
            $writerExits = $this->pool->stop();
            $this->server->stop();
            $this->writes->restore();
        }

        return $this->summarise($window, $writers, $writerExits, $notes);
    }

    /**
     * @param Closure(string): void $report
     *
     * @return array{from: int, to: int, client_statuses: array<int, int>, requests: int}
     *
     * @throws RuntimeException
     */
    private function sendLoad(int $warmup, int $requests, Closure $report): array
    {
        $port = $this->server->start($this->runtime->uninstrumentedEnvironment());
        $report("Octane is up on 127.0.0.1:{$port}; sending {$warmup} warm-up and {$requests} measured requests at " . BenchRequestLoad::REQUESTS_PER_SECOND . ' req/s.');

        $from = 0;
        $statuses = $this->load->run($port, $warmup, $requests, static function () use (&$from, $report): void {
            // intentional: Octane logs a request after sending its response, so the last warm-up line can still be pending.
            \sleep(self::LOG_FLUSH_SECONDS);
            $from = OctaneLogDurations::currentOffset();
            $report('Warm-up done; measuring.');
        });

        \sleep(self::LOG_FLUSH_SECONDS);

        return ['from' => $from, 'to' => OctaneLogDurations::currentOffset(), 'client_statuses' => $statuses, 'requests' => $requests];
    }

    /**
     * @param array{from: int, to: int, client_statuses: array<int, int>, requests: int} $window
     * @param list<BenchWriter> $writers
     * @param array<string, int> $writerExits
     * @param list<string> $notes
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     * @throws JsonException
     */
    private function summarise(array $window, array $writers, array $writerExits, array $notes): array
    {
        $log = OctaneLogDurations::between($window['from'], $window['to'], self::MEASURED_PATH);
        $summary = [
            'measured_at' => CarbonImmutable::now()->toIso8601String(),
            'requests_sent' => $window['requests'],
            'server_lines' => \count($log['durations']),
            'server_statuses' => $log['statuses'],
            'client_statuses' => $window['client_statuses'],
            ...self::percentiles($log['durations']),
            'request_mix' => ProductListRequestShapes::BENCH_MIX,
            'writers' => \array_map(static fn(BenchWriter $writer): string => $writer->describe(), $writers),
            'writer_exit_codes' => $writerExits,
            'notes' => $notes,
        ];

        $this->writeSummary($summary);

        return $summary;
    }

    /**
     * @param array<string, mixed> $summary
     *
     * @throws JsonException
     */
    private function writeSummary(array $summary): void
    {
        $directory = \base_path('tmp/cor-258/bench');
        $this->files->ensureDirectoryExists($directory);
        $this->files->put($directory . '/load-' . CarbonImmutable::now()->format('Ymd\THis') . '.json', \json_encode($summary, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<float> $durations
     *
     * @return array<string, float|null>
     */
    private static function percentiles(array $durations): array
    {
        if ($durations === []) {
            return ['p50_ms' => null, 'p95_ms' => null, 'p99_ms' => null, 'max_ms' => null];
        }

        return [
            'p50_ms' => BenchStatistics::percentile($durations, 50.0),
            'p95_ms' => BenchStatistics::percentile($durations, 95.0),
            'p99_ms' => BenchStatistics::percentile($durations, 99.0),
            'max_ms' => \max($durations),
        ];
    }
}
