<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

/**
 * The hourly catch-up job's run, as its failure handler sees it.
 */
final readonly class OrderCatchUpRunDTO
{
    public function __construct(
        public string $runId,
    ) {}
}
