<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use DateTimeImmutable;

/**
 * Dispatch Linnworks purchase order backfill jobs to the queue.
 *
 * @template-pattern Application Contract Interface
 */
interface PurchaseOrderBackfillDispatcherInterface
{
    public function dispatchDateRangeBackfill(DateTimeImmutable $from, DateTimeImmutable $to): void;

    public function dispatchAllBackfill(): void;
}
