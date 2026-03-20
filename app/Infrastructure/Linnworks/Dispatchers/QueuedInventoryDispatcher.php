<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Dispatchers;

use App\Application\Contracts\Inventory\InventoryDispatcherInterface;
use App\Domain\Inventory\Commands\UpdateSkuCommand;
use App\Infrastructure\Jobs\Inventory\UpdateSkuJob;
use Override;

/**
 * Queue-backed dispatcher for inventory update tasks.
 *
 * Translates Application-layer dispatch intents into concrete Laravel job dispatches.
 */
final readonly class QueuedInventoryDispatcher implements InventoryDispatcherInterface
{
    #[Override]
    public function dispatchSkuUpdate(UpdateSkuCommand $command): void
    {
        UpdateSkuJob::dispatch($command);
    }
}
