<?php

declare(strict_types=1);

namespace Tests\Fixtures\QuickBooks;

use App\Application\Contracts\QuickBooks\OrderSyncRunRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncRunCountsDTO;
use App\Application\QuickBooks\OrderSync\DTOs\RequestedOrderSyncRunDTO;
use Override;
use Throwable;

/**
 * Records each run write in order, as `[method, run id, ...arguments]`.
 */
final class SpyOrderSyncRunRepository implements OrderSyncRunRepositoryInterface
{
    /** @var list<array{string, string|null, mixed...}> */
    public array $calls = [];

    public ?Throwable $throwOnEnd = null;

    #[Override]
    public function record(RequestedOrderSyncRunDTO $run): void
    {
        $this->calls[] = ['record', $run->id, $run];
    }

    #[Override]
    public function addOpenJob(?string $runId): void
    {
        $this->calls[] = ['addOpenJob', $runId];
    }

    #[Override]
    public function removeOpenJob(?string $runId): void
    {
        $this->calls[] = ['removeOpenJob', $runId];
    }

    #[Override]
    public function markStarted(?string $runId): void
    {
        $this->calls[] = ['markStarted', $runId];
    }

    #[Override]
    public function endJob(?string $runId, OrderSyncRunCountsDTO $counts, bool $failed): void
    {
        if ($this->throwOnEnd !== null) {
            throw $this->throwOnEnd;
        }

        $this->calls[] = ['endJob', $runId, $counts, $failed];
    }

    #[Override]
    public function endRangeJob(?string $runId, int $candidateCount): void
    {
        $this->calls[] = ['endRangeJob', $runId, $candidateCount];
    }

    #[Override]
    public function addCounts(?string $runId, OrderSyncRunCountsDTO $counts, bool $failed): void
    {
        $this->calls[] = ['addCounts', $runId, $counts, $failed];
    }

    #[Override]
    public function failRequest(string $runId): void
    {
        $this->calls[] = ['failRequest', $runId];
    }
}
