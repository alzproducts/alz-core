<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Dispatchers;

use App\Application\Contracts\Linnworks\LinnworksSyncDispatcherInterface;
use App\Application\Jobs\Linnworks\SyncLinnworksStockItemsJob;
use App\Application\Jobs\Linnworks\SyncStockItemJob;
use App\Domain\ValueObjects\Guid;
use Override;

/**
 * Queue-backed dispatcher for Linnworks stock item synchronisation.
 *
 * Translates Application-layer dispatch intents into concrete Laravel job dispatches.
 */
final readonly class QueuedLinnworksSyncDispatcher implements LinnworksSyncDispatcherInterface
{
    #[Override]
    public function dispatchStockItemSync(Guid $stockItemId): void
    {
        SyncStockItemJob::dispatch($stockItemId);
    }

    #[Override]
    public function dispatchFullStockItemsSync(): void
    {
        SyncLinnworksStockItemsJob::dispatch();
    }
}
