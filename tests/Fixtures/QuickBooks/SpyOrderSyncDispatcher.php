<?php

declare(strict_types=1);

namespace Tests\Fixtures\QuickBooks;

use App\Application\Contracts\QuickBooks\OrderSyncDispatcherInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderChunkRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderRangeRunDTO;
use Override;
use Throwable;

final class SpyOrderSyncDispatcher implements OrderSyncDispatcherInterface
{
    /** @var list<OrderRangeRunDTO> */
    public array $ranges = [];

    /** @var list<OrderChunkRunDTO> */
    public array $chunks = [];

    public ?Throwable $throws = null;

    #[Override]
    public function dispatchRange(OrderRangeRunDTO $run): void
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        $this->ranges[] = $run;
    }

    #[Override]
    public function dispatchChunk(OrderChunkRunDTO $run): void
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        $this->chunks[] = $run;
    }
}
