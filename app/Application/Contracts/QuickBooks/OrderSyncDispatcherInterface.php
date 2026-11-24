<?php

declare(strict_types=1);

namespace App\Application\Contracts\QuickBooks;

use App\Application\QuickBooks\OrderSync\DTOs\OrderChunkRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderRangeRunDTO;

interface OrderSyncDispatcherInterface
{
    public function dispatchRange(OrderRangeRunDTO $run): void;

    /**
     * A re-attempt is delayed.
     */
    public function dispatchChunk(OrderChunkRunDTO $run): void;
}
