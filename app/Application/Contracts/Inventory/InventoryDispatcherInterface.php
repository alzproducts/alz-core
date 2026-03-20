<?php

declare(strict_types=1);

namespace App\Application\Contracts\Inventory;

use App\Domain\Inventory\Commands\UpdateSkuCommand;

/**
 * Dispatch inventory update tasks.
 *
 * Application layer uses this to trigger async processing without
 * knowing the delivery mechanism (queue, inline, etc.).
 */
interface InventoryDispatcherInterface
{
    public function dispatchSkuUpdate(UpdateSkuCommand $command): void;
}
