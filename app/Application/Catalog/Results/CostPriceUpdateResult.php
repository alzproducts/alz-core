<?php

declare(strict_types=1);

namespace App\Application\Catalog\Results;

/**
 * Result of a bulk cost price update operation.
 *
 * Tracks total items, successes, and per-item failures.
 */
final readonly class CostPriceUpdateResult
{
    /**
     * @param int<0, max> $total
     * @param int<0, max> $succeeded
     * @param list<FailedCostPriceUpdateResult> $failures
     * @param bool $wholeBatchWriteFailed True only when the bulk Linnworks write itself failed for
     *        every item — distinct from a per-item, all-unresolvable, or local-DB-mirror zero-success.
     *        Lets an async caller retry a genuine write outage without retrying a permanently-failed batch.
     */
    public function __construct(
        public int $total,
        public int $succeeded,
        public array $failures,
        public bool $wholeBatchWriteFailed = false,
    ) {}

    public function allSucceeded(): bool
    {
        return $this->failures === [];
    }

    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }
}
