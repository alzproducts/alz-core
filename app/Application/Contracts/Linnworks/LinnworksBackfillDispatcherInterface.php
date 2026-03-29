<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use DateTimeImmutable;

/**
 * Dispatch Linnworks order backfill jobs to the queue.
 *
 * @template-pattern Application Contract Interface
 */
interface LinnworksBackfillDispatcherInterface
{
    /**
     * Dispatch a date-range backfill job.
     */
    public function dispatchDateRangeBackfill(DateTimeImmutable $from, DateTimeImmutable $to): void;

    /**
     * Dispatch a full historical backfill job.
     */
    public function dispatchFullBackfill(): void;
}
