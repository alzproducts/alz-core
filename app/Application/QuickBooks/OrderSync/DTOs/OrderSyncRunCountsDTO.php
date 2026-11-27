<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use App\Application\QuickBooks\OrderSync\Enums\SyncOutcome;

/**
 * How many references of a run ended in each outcome.
 */
final readonly class OrderSyncRunCountsDTO
{
    public function __construct(
        public int $synced,
        public int $alreadySynced,
        public int $skipped,
        public int $failed,
        public int $notAttempted,
    ) {}

    public static function zero(): self
    {
        return new self(0, 0, 0, 0, 0);
    }

    /**
     * @param list<OrderSyncResultDTO> $results
     */
    public static function ofResults(array $results): self
    {
        $counts = \array_fill_keys(\array_column(SyncOutcome::cases(), 'value'), 0);
        foreach ($results as $result) {
            $counts[$result->outcome->value]++;
        }

        return new self(
            $counts[SyncOutcome::Synced->value],
            $counts[SyncOutcome::AlreadySynced->value],
            $counts[SyncOutcome::Skipped->value],
            $counts[SyncOutcome::Failed->value],
            $counts[SyncOutcome::NotAttempted->value],
        );
    }

    public function withNotAttempted(int $references): self
    {
        return new self($this->synced, $this->alreadySynced, $this->skipped, $this->failed, $this->notAttempted + $references);
    }
}
