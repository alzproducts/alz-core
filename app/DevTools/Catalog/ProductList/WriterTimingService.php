<?php

declare(strict_types=1);

namespace App\DevTools\Catalog\ProductList;

use Closure;
use RuntimeException;

/**
 * Times the daily sync's variation replace and the stock mirror, five runs each, then restores the stock levels.
 */
final readonly class WriterTimingService
{
    public const int RUNS = 5;

    public function __construct(
        private CatalogueWriter $catalogue,
        private StockMirrorWriter $stockMirror,
        private CatalogueRowsSnapshot $catalogueRows,
    ) {}

    /**
     * @param Closure(string, int, float): void $onRun Receives the writer name, run number and duration in ms
     *
     * @return array<string, list<float>> Writer name => run durations in ms
     *
     * @throws RuntimeException
     */
    public function measure(Closure $onRun): array
    {
        $this->catalogue->captureBaseline();
        $products = $this->catalogue->loadCatalogue();
        $this->catalogueRows->capture();
        $this->stockMirror->captureBaseline();

        try {
            return [
                'variation_replace' => $this->time('variation_replace', function () use ($products): void {
                    $this->catalogue->replaceVariations($products);
                }, $onRun),
                'stock_mirror' => $this->time('stock_mirror', $this->stockMirror->mirror(...), $onRun),
            ];
        } finally {
            $this->restore();
        }
    }

    /**
     * @throws RuntimeException
     */
    private function restore(): void
    {
        try {
            $this->stockMirror->restore();
        } finally {
            $this->catalogueRows->restore();
        }
    }

    /**
     * @param Closure(): void $write
     * @param Closure(string, int, float): void $onRun
     *
     * @param-immediately-invoked-callable $write
     *
     * @return list<float>
     *
     * @throws RuntimeException
     */
    private function time(string $name, Closure $write, Closure $onRun): array
    {
        $durations = [];

        for ($run = 1; $run <= self::RUNS; $run++) {
            $startedAt = BenchStatistics::nowMilliseconds();
            $write();
            $durations[] = BenchStatistics::elapsedMilliseconds($startedAt);
            $onRun($name, $run, $durations[\count($durations) - 1]);
        }

        return $durations;
    }
}
