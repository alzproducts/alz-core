<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use App\Domain\ValueObjects\Guid;

/**
 * Dispatch Linnworks stock item synchronisation tasks.
 *
 * Application layer uses this to trigger async sync without
 * knowing the delivery mechanism (queue, inline, etc.).
 */
interface LinnworksSyncDispatcherInterface
{
    public function dispatchStockItemSync(Guid $stockItemId): void;

    public function dispatchFullStockItemsSync(): void;
}
