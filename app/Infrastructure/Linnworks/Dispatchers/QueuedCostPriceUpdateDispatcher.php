<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Dispatchers;

use App\Application\Contracts\Linnworks\CostPriceUpdateDispatcherInterface;
use App\Domain\Catalog\Product\Commands\UpdateCostPriceCommand;
use App\Infrastructure\Jobs\Linnworks\UpdateCostPriceBatchJob;
use Override;

/**
 * Queue-backed dispatcher for bulk cost-price updates.
 *
 * Translates an Application-layer dispatch intent into one queued job per supplier-chunk.
 */
final readonly class QueuedCostPriceUpdateDispatcher implements CostPriceUpdateDispatcherInterface
{
    /**
     * @param non-empty-list<UpdateCostPriceCommand> $commands
     */
    #[Override]
    public function dispatchCostPriceBatch(string $supplierName, array $commands): void
    {
        UpdateCostPriceBatchJob::dispatch($supplierName, $commands);
    }
}
