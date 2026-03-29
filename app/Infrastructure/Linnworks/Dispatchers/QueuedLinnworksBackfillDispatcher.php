<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Dispatchers;

use App\Application\Contracts\Linnworks\LinnworksBackfillDispatcherInterface;
use App\Infrastructure\Jobs\Linnworks\SyncHistoricalLinnworksOrdersJob;
use App\Infrastructure\Jobs\Linnworks\SyncLinnworksOrdersByDateRangeJob;
use DateTimeImmutable;
use Override;

/**
 * Queue-backed dispatcher for Linnworks order backfill jobs.
 *
 * Translates Application-layer dispatch intents into concrete Laravel job dispatches.
 */
final readonly class QueuedLinnworksBackfillDispatcher implements LinnworksBackfillDispatcherInterface
{
    #[Override]
    public function dispatchDateRangeBackfill(DateTimeImmutable $from, DateTimeImmutable $to): void
    {
        SyncLinnworksOrdersByDateRangeJob::dispatch($from, $to);
    }

    #[Override]
    public function dispatchFullBackfill(): void
    {
        SyncHistoricalLinnworksOrdersJob::dispatch();
    }
}
