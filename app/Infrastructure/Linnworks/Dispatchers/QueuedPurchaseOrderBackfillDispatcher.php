<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Dispatchers;

use App\Application\Contracts\Linnworks\PurchaseOrderBackfillDispatcherInterface;
use App\Infrastructure\Jobs\Linnworks\SyncAllPurchaseOrdersJob;
use App\Infrastructure\Jobs\Linnworks\SyncPurchaseOrdersByDateRangeJob;
use DateTimeImmutable;
use Override;

/**
 * Queue-backed dispatcher for Linnworks purchase order backfill jobs.
 */
final readonly class QueuedPurchaseOrderBackfillDispatcher implements PurchaseOrderBackfillDispatcherInterface
{
    #[Override]
    public function dispatchDateRangeBackfill(DateTimeImmutable $from, DateTimeImmutable $to): void
    {
        SyncPurchaseOrdersByDateRangeJob::dispatch($from, $to);
    }

    #[Override]
    public function dispatchAllBackfill(): void
    {
        SyncAllPurchaseOrdersJob::dispatch();
    }
}
