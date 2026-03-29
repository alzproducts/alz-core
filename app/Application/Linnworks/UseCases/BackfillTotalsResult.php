<?php

declare(strict_types=1);

namespace App\Application\Linnworks\UseCases;

use App\Application\Results\SaveManyResult;
use App\Application\Results\SyncResult;

/**
 * Mutable accumulator for backfill progress tracking.
 *
 * Tracks fetched/saved/failed counts during the buffer/flush loop
 * and converts to an immutable SyncResult when complete.
 *
 * @internal Used only by BackfillLinnworksOrdersUseCase
 */
final class BackfillTotalsResult
{
    /** @var int<0, max> */
    private int $fetched = 0;

    /** @var int<0, max> */
    private int $saved = 0;

    /** @var int<0, max> */
    private int $failed = 0;

    /** @var list<int|string> */
    private array $failedReferences = [];

    /**
     * Record fetched orders from a chunk.
     *
     * @param int<0, max> $count
     */
    public function addFetched(int $count): void
    {
        $this->fetched += $count;
    }

    /**
     * Accumulate a flush result into running totals.
     */
    public function accumulateFlush(SaveManyResult $result): void
    {
        $this->saved += $result->succeeded;
        $this->failed += $result->failed;
        \array_push($this->failedReferences, ...$result->failedReferences);
    }

    /**
     * Convert to an immutable SyncResult.
     */
    public function toSyncResult(): SyncResult
    {
        return new SyncResult(
            fetched: $this->fetched,
            saved: $this->saved,
            failed: $this->failed,
            failedReferences: $this->failedReferences,
        );
    }

    /**
     * Get log-friendly context array.
     *
     * @return array{fetched: int<0, max>, saved: int<0, max>, failed: int<0, max>}
     */
    public function toLogContext(): array
    {
        return [
            'fetched' => $this->fetched,
            'saved' => $this->saved,
            'failed' => $this->failed,
        ];
    }
}
