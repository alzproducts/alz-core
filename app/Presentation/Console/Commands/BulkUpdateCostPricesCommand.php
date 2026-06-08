<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\Linnworks\BulkCostPriceUpdate\DispatchBulkCostPriceJobsUseCase;
use App\Application\Linnworks\BulkCostPriceUpdate\Results\BulkCostPriceDispatchResult;
use App\Application\Linnworks\BulkCostPriceUpdate\SupplierCostPriceBatchDTO;
use App\Presentation\Console\Parsers\CostPriceCsvParser;
use Illuminate\Console\Command;

/**
 * Bulk update Linnworks cost prices from a CSV, fanning out one queued job per
 * supplier-chunk (≤100 SKUs). Each chunk mirrors PUT products/cost-prices exactly:
 * same Linnworks bulk write, cost_price_changes audit trail, DB mirror, reconciliation syncs.
 *
 * ⚠️ PRODUCTION ONLY — writes live Linnworks cost prices. Run against the production worker;
 * a local run would use production Linnworks credentials but the local DB, stranding the audit
 * trail in the wrong database (prior incident with inventory:update-skus).
 *
 *   railway ssh -s alz-core-worker php artisan inventory:bulk-update-cost-prices storage/costs.csv --dry-run
 *   railway ssh -s alz-core-worker php artisan inventory:bulk-update-cost-prices storage/costs.csv
 *
 * CSV format (header row required): supplier,sku,cost_price   (cost_price is net / ex-VAT).
 */
final class BulkUpdateCostPricesCommand extends Command
{
    protected $signature = 'inventory:bulk-update-cost-prices
                            {file : Path to CSV file (columns: supplier,sku,cost_price)}
                            {--dry-run : Preview suppliers, SKU counts and job counts without dispatching}';

    protected $description = 'Bulk update Linnworks cost prices from a CSV via queued jobs';

    public function handle(DispatchBulkCostPriceJobsUseCase $useCase, CostPriceCsvParser $parser): int
    {
        $file = $this->argument('file');
        if (! \is_file($file) || ! \is_readable($file)) {
            $this->error("CSV file not found or not readable: {$file}");
            return self::FAILURE;
        }

        [$batches, $errors] = $parser->parse($file);
        if ($errors !== []) {
            $this->reportRowErrors($errors);
            return self::FAILURE;
        }

        if ($batches === []) {
            $this->error('CSV contained no data rows.');
            return self::FAILURE;
        }

        return $this->dispatchOrPreview($useCase, $batches);
    }

    /**
     * @param list<SupplierCostPriceBatchDTO> $batches
     */
    private function dispatchOrPreview(DispatchBulkCostPriceJobsUseCase $useCase, array $batches): int
    {
        if ($this->option('dry-run')) {
            $this->renderPreview($batches);

            return self::SUCCESS;
        }

        $this->renderSummary($useCase->execute($batches));

        return self::SUCCESS;
    }

    /**
     * @param list<SupplierCostPriceBatchDTO> $batches
     */
    private function renderPreview(array $batches): void
    {
        $rows = [];
        $totalSkus = 0;
        $totalJobs = 0;
        foreach ($batches as $batch) {
            $skuCount = \count($batch->commands);
            $jobCount = (int) \ceil($skuCount / DispatchBulkCostPriceJobsUseCase::CHUNK_SIZE);
            $rows[] = [$batch->supplierName, $skuCount, $jobCount];
            $totalSkus += $skuCount;
            $totalJobs += $jobCount;
        }

        $this->info('DRY RUN — no jobs dispatched:');
        $this->newLine();
        $this->table(['Supplier', 'SKUs', 'Jobs'], $rows);
        $this->line(\sprintf('  Totals: %d suppliers, %d SKUs, %d jobs', \count($batches), $totalSkus, $totalJobs));
        $this->newLine();
        $this->warn('Remove --dry-run to dispatch.');
    }

    private function renderSummary(BulkCostPriceDispatchResult $result): void
    {
        $this->info('✓ Bulk cost price jobs dispatched:');
        $this->table(
            ['Suppliers', 'SKUs', 'Jobs'],
            [[$result->supplierCount, $result->skuCount, $result->jobsDispatched]],
        );
        $this->line('  Monitor progress: php artisan horizon');
    }

    /**
     * @param list<string> $errors
     */
    private function reportRowErrors(array $errors): void
    {
        $this->error(\sprintf('Found %d invalid row(s) — nothing dispatched:', \count($errors)));
        foreach ($errors as $error) {
            $this->line('  • ' . $error);
        }
    }
}
