<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;
use App\Domain\ValueObjects\DateRange;
use DateTimeImmutable;

/**
 * An accepted run, as recorded before its first job is queued. A range run has a range; a manual run has references.
 */
final readonly class RequestedOrderSyncRunDTO
{
    /**
     * @param non-empty-list<int>|null $references
     */
    public function __construct(
        public string $id,
        public OrderSyncTrigger $trigger,
        public bool $forceRetry,
        public ?DateRange $range,
        public ?array $references,
        public int $candidateCount,
        public DateTimeImmutable $requestedAt,
    ) {}
}
