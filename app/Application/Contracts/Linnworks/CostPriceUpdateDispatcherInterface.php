<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use App\Domain\Catalog\Product\Commands\UpdateCostPriceCommand;

/**
 * Dispatch a queued cost-price update for one supplier-chunk of SKUs.
 *
 * Application fans bulk cost-price work out through this contract without knowing
 * the delivery mechanism (queue, inline, etc.).
 */
interface CostPriceUpdateDispatcherInterface
{
    /**
     * @param non-empty-list<UpdateCostPriceCommand> $commands
     */
    public function dispatchCostPriceBatch(string $supplierName, array $commands): void;
}
