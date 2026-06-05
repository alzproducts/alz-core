<?php

declare(strict_types=1);

namespace App\Application\Linnworks\BulkCostPriceUpdate\Results;

/**
 * Summary of a bulk cost-price dispatch: suppliers, SKUs, and queued jobs.
 */
final readonly class BulkCostPriceDispatchResult
{
    /**
     * @param int<0, max> $supplierCount
     * @param int<0, max> $skuCount
     * @param int<0, max> $jobsDispatched
     */
    public function __construct(
        public int $supplierCount,
        public int $skuCount,
        public int $jobsDispatched,
    ) {}
}
