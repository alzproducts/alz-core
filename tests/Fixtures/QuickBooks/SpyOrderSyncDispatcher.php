<?php

declare(strict_types=1);

namespace Tests\Fixtures\QuickBooks;

use App\Application\Contracts\QuickBooks\OrderSyncDispatcherInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderChunkRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderRangeRunDTO;
use Override;

final class SpyOrderSyncDispatcher implements OrderSyncDispatcherInterface
{
    /** @var list<OrderRangeRunDTO> */
    public array $ranges = [];

    /** @var list<OrderChunkRunDTO> */
    public array $chunks = [];

    #[Override]
    public function dispatchRange(OrderRangeRunDTO $run): void
    {
        $this->ranges[] = $run;
    }

    #[Override]
    public function dispatchChunk(OrderChunkRunDTO $run): void
    {
        $this->chunks[] = $run;
    }
}
