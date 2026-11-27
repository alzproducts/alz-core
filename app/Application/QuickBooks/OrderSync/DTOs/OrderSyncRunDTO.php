<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use App\Application\QuickBooks\OrderSync\Enums\OrderSyncRunStatus;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;
use App\Domain\ValueObjects\DateRange;
use DateTimeImmutable;

/**
 * One recorded run, as the run history lists it.
 */
final readonly class OrderSyncRunDTO
{
    /**
     * @param list<int>|null $references
     */
    public function __construct(
        public string $id,
        public OrderSyncTrigger $trigger,
        public bool $forceRetry,
        public ?DateRange $range,
        public ?array $references,
        public OrderSyncRunStatus $status,
        public int $candidateCount,
        public OrderSyncRunCountsDTO $counts,
        public DateTimeImmutable $requestedAt,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt,
    ) {}
}
