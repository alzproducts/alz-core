<?php

declare(strict_types=1);

namespace App\Application\Shopwired\BulkSellingPriceUpdate\Results;

/**
 * Summary of a bulk selling-price dispatch: products, SKUs, and queued jobs.
 */
final readonly class BulkSellingPriceDispatchResult
{
    /**
     * @param int<0, max> $productCount
     * @param int<0, max> $skuCount
     * @param int<0, max> $jobsDispatched
     */
    public function __construct(
        public int $productCount,
        public int $skuCount,
        public int $jobsDispatched,
    ) {}
}
