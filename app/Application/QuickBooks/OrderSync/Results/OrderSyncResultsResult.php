<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Results;

use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncResultDTO;
use App\Application\QuickBooks\OrderSync\Enums\SyncOutcome;
use LogicException;

/**
 * One result per reference, in request order.
 */
final readonly class OrderSyncResultsResult
{
    /**
     * @param list<OrderSyncResultDTO> $results
     */
    private function __construct(
        public array $results,
    ) {}

    /**
     * @param non-empty-list<int>      $references
     * @param list<OrderSyncResultDTO> $results    in any order
     */
    public static function inRequestOrder(array $references, array $results): self
    {
        $byReference = [];
        foreach ($results as $result) {
            $byReference[$result->reference] = $result;
        }

        $ordered = [];
        foreach ($references as $reference) {
            $ordered[] = $byReference[$reference] ?? throw new LogicException('Every reference has a result');
        }

        return new self($ordered);
    }

    /**
     * @return array<string, int> every outcome, zeros included
     */
    public function counts(): array
    {
        $counts = \array_fill_keys(\array_column(SyncOutcome::cases(), 'value'), 0);
        foreach ($this->results as $result) {
            $counts[$result->outcome->value]++;
        }

        return $counts;
    }
}
