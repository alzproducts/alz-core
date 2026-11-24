<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Results;

final readonly class OrderRangeSyncAcceptedResult
{
    /**
     * @param string|null $runId null only when there is nothing to sync
     */
    private function __construct(
        public ?string $runId,
        public int $candidateCount,
    ) {}

    public static function queued(string $runId, int $candidateCount): self
    {
        return new self($runId, $candidateCount);
    }

    public static function nothingToSync(): self
    {
        return new self(null, 0);
    }
}
