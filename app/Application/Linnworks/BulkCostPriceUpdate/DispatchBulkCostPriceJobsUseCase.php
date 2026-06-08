<?php

declare(strict_types=1);

namespace App\Application\Linnworks\BulkCostPriceUpdate;

use App\Application\Contracts\Linnworks\CostPriceUpdateDispatcherInterface;
use App\Application\Linnworks\BulkCostPriceUpdate\Results\BulkCostPriceDispatchResult;
use Psr\Log\LoggerInterface;

/**
 * Fan a large set of supplier-grouped cost-price commands out into queued jobs —
 * one job per supplier-chunk of at most CHUNK_SIZE SKUs, mirroring the batch boundary
 * of the synchronous PUT products/cost-prices endpoint.
 */
final readonly class DispatchBulkCostPriceJobsUseCase
{
    /** Mirrors the PUT products/cost-prices DTO Max(100) cap — the Linnworks bulk-write batch boundary. */
    public const int CHUNK_SIZE = 100;

    public function __construct(
        private CostPriceUpdateDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param list<SupplierCostPriceBatchDTO> $batches
     */
    public function execute(array $batches): BulkCostPriceDispatchResult
    {
        $this->logger->info('Dispatching bulk cost price update jobs', [
            'supplier_count' => \count($batches),
        ]);

        $result = $this->dispatchChunks($batches);

        $this->logger->info('Bulk cost price update jobs dispatched', [
            'supplier_count' => $result->supplierCount,
            'sku_count' => $result->skuCount,
            'jobs_dispatched' => $result->jobsDispatched,
        ]);

        return $result;
    }

    /**
     * @param list<SupplierCostPriceBatchDTO> $batches
     */
    private function dispatchChunks(array $batches): BulkCostPriceDispatchResult
    {
        $skuCount = 0;
        $jobsDispatched = 0;

        foreach ($batches as $batch) {
            foreach (\array_chunk($batch->commands, self::CHUNK_SIZE) as $chunk) {
                $this->dispatcher->dispatchCostPriceBatch($batch->supplierName, $chunk);
                $skuCount += \count($chunk);
                $jobsDispatched++;
            }
        }

        return new BulkCostPriceDispatchResult(\count($batches), $skuCount, $jobsDispatched);
    }
}
